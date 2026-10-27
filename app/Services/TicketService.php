<?php

namespace App\Services;

use App\Enums\Priority;
use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Enums\Zone;
use App\Models\Atm;
use App\Models\AtmCondition;
use App\Models\FaultType;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Ciclo de vida del ticket: apertura, asignación, cambios de estado y visitas. */
class TicketService
{
    /** Enviar notificaciones push del ciclo. Se desactiva al generar datos de demostración o importar. */
    public bool $notify = true;

    /** Máximo que se acepta retrodatar un evento enviado por el teléfono (p. ej. hecho sin señal). */
    private const MAX_BACKDATE_HOURS = 24;

    /** Diferencia tolerada entre el reloj del teléfono y el del servidor en un envío hecho con señal. */
    private const CLOCK_TOLERANCE_SECONDS = 120;

    /** A partir de esta demora entre lo ocurrido y lo recibido, el registro se muestra como "enviado tarde". */
    public const LATE_REPORT_SECONDS = 300;

    public function __construct(
        private TicketNumberGenerator $numbers,
        private ConstanciaStorage $constancias,
        private PushNotifier $push,
    ) {}

    /**
     * Abre un ticket (RF-2.1/2.2) y, si se indica, lo asigna al técnico.
     *
     * @param  array{atm_id: int, fault_type_id?: int|null, priority?: string|null, sla_zone?: string|null, description?: string|null, bank_reference?: string|null, assigned_to?: int|null}  $data
     */
    public function open(array $data, User $operator): Ticket
    {
        return DB::transaction(function () use ($data, $operator) {
            $atm = Atm::findOrFail($data['atm_id']);
            $now = CarbonImmutable::now();

            $ticket = Ticket::create([
                'number' => $this->numbers->next($now->year),
                'atm_id' => $atm->id,
                'bank_id' => $atm->bank_id,
                'fault_type_id' => $data['fault_type_id'] ?? null,
                'description' => $data['description'] ?? null,
                'bank_reference' => $data['bank_reference'] ?? null,
                'status' => TicketStatus::Open,
                'sla_zone' => Zone::tryFrom((string) ($data['sla_zone'] ?? '')) !== Zone::tryFrom((string) $atm->zone) ? ($data['sla_zone'] ?? null) : null,
                'priority' => $data['priority'] ?? Priority::Medium->value,
                'source' => 'manual',
                'opened_by' => $operator->id,
                'opened_at' => $now,
            ]);
            $this->log($ticket, $operator, null, TicketStatus::Open, 'Ticket abierto');

            if (! empty($data['assigned_to'])) {
                $this->assign($ticket, User::findOrFail($data['assigned_to']), $operator);
            }

            return $ticket->refresh();
        });
    }

    /**
     * Abre un ticket a partir de un correo del banco. El reloj del SLA corre desde
     * que el banco envió el reporte, no desde que el sistema lo leyó.
     *
     * @param  array{fault_type_id?: int|null, priority?: string|null, sla_zone?: string|null, description?: string|null}  $data
     * @param  User|null  $by  quien lo abrió desde la bandeja de correos; null si lo abrió el sistema
     */
    public function openFromEmail(Atm $atm, array $data, CarbonImmutable $sentAt, ?User $by = null): Ticket
    {
        return DB::transaction(function () use ($atm, $data, $sentAt, $by) {
            $now = CarbonImmutable::now();
            $openedAt = ($sentAt->greaterThan($now) ? $now : $sentAt)->setTimezone(config('app.timezone'));

            $ticket = Ticket::create([
                'number' => $this->numbers->next($now->year),
                'atm_id' => $atm->id,
                'bank_id' => $atm->bank_id,
                'fault_type_id' => $data['fault_type_id'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => TicketStatus::Open,
                'sla_zone' => Zone::tryFrom((string) ($data['sla_zone'] ?? '')) !== Zone::tryFrom((string) $atm->zone) ? ($data['sla_zone'] ?? null) : null,
                'priority' => $data['priority'] ?? Priority::Medium->value,
                'source' => 'email',
                'opened_by' => $by?->id,
                'opened_at' => $openedAt,
            ]);
            $this->log($ticket, $by, null, TicketStatus::Open, 'Ticket abierto por correo del banco', $openedAt);

            return $ticket->refresh();
        });
    }

    public function assign(Ticket $ticket, User $technician, User $by): Ticket
    {
        if ($technician->role !== Role::Technician || ! $technician->active) {
            throw ValidationException::withMessages(['technician_id' => 'Debe seleccionar un técnico activo.']);
        }
        if ($ticket->status->isFinal()) {
            throw ValidationException::withMessages(['status' => 'El ticket ya está cerrado.']);
        }
        if ($ticket->status === TicketStatus::PendingClosure) {
            throw ValidationException::withMessages(['status' => 'El técnico ya informó el cierre. Confírmelo o devuélvalo antes de reasignar.']);
        }

        $from = $ticket->status;
        $previous = $ticket->assigned_to !== $technician->id ? $ticket->technician : null;
        $ticket->update([
            'assigned_to' => $technician->id,
            'assigned_at' => $ticket->assigned_at ?? CarbonImmutable::now(),
            'status' => TicketStatus::Assigned,
        ]);
        $ticket->unsetRelation('technician');
        // El técnico anterior deja de seguirlo si ya no puede verlo (no llegó a atenderlo).
        if ($previous && $previous->cannot('view', $ticket)) {
            $ticket->watchers()->detach($previous->id);
        }
        $this->log($ticket, $by, $from, TicketStatus::Assigned, "Asignado a {$technician->name}");

        if ($this->notify) {
            // Se envía después de responder, para no demorar al operador.
            defer(fn () => $this->push->ticketAssigned($ticket->fresh()));
            if ($previous) {
                defer(fn () => $this->push->ticketReassigned($ticket->fresh(), $previous, $technician));
            }
        }

        return $ticket;
    }

    /**
     * Cambios manuales de estado: en tránsito, en sitio, anulado. $at es la hora que
     * informa el teléfono; solo se usa si el cambio estuvo guardado sin señal ($queued).
     */
    public function transition(Ticket $ticket, TicketStatus $to, User $by, ?string $note = null, ?CarbonImmutable $at = null, bool $queued = false): Ticket
    {
        $allowed = [TicketStatus::InTransit, TicketStatus::OnSite, TicketStatus::Cancelled];
        if (! in_array($to, $allowed, true) || ! $ticket->status->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'status' => "No se puede pasar de \"{$ticket->status->label()}\" a \"{$to->label()}\".",
            ]);
        }

        $from = $ticket->status;
        $at = $this->clientTime($ticket, $at, $queued, $this->lastEventAt($ticket));
        $ticket->update(['status' => $to]
            + ($to === TicketStatus::Cancelled ? $this->closingTimes($ticket, $at) : [])
            + ($to === TicketStatus::OnSite && ! $ticket->responded_at ? ['responded_at' => $at] : []));
        $this->log($ticket, $by, $from, $to, $note, $at);

        if ($this->notify) {
            defer(fn () => $to === TicketStatus::Cancelled
                ? $this->push->ticketCancelled($ticket->fresh(), $by, $note)
                : $this->push->technicianMoved($ticket->fresh(), $by, $to === TicketStatus::OnSite, $at));
        }

        return $ticket;
    }

    /**
     * Registra la atención en sitio (RF-3.1 a RF-3.4). El estado del ticket
     * pasa a ser el que corresponde a la condición final del cajero.
     *
     * @param  array{client_uuid?: string|null, constancia_number?: string|null, fault_type_id?: int|null, work_done?: string|null, atm_condition_id: int, arrived_at?: string|null, finished_at?: string|null, parts?: list<array{part_id: int, quantity: int}>}  $data
     */
    public function registerVisit(Ticket $ticket, array $data, User $by, ?UploadedFile $photo = null): Visit
    {
        $photoPath = null;
        try {
            return DB::transaction(function () use ($ticket, $data, $by, $photo, &$photoPath) {
                // Se bloquea el ticket: dos envíos simultáneos (dos pestañas, un reintento) se
                // procesan uno tras otro, y el segundo ya ve lo que hizo el primero.
                Ticket::whereKey($ticket->id)->lockForUpdate()->first();
                $ticket->refresh();

                // Reintento de una sincronización offline: devolver la visita ya creada.
                if (! empty($data['client_uuid']) && $existing = $ticket->visits()->firstWhere('client_uuid', $data['client_uuid'])) {
                    return $existing;
                }
                if ($ticket->status->isFinal() || in_array($ticket->status, [TicketStatus::Open, TicketStatus::PendingClosure], true)) {
                    throw ValidationException::withMessages([
                        'status' => match ($ticket->status) {
                            TicketStatus::Open => 'Asigne el ticket a un técnico antes de registrar la atención.',
                            TicketStatus::PendingClosure => 'El cierre ya fue informado y espera la confirmación del administrador.',
                            default => 'El ticket ya está cerrado.',
                        },
                    ]);
                }

                return $this->storeVisit($ticket, $data, $by, $photo, $photoPath);
            });
        } catch (UniqueConstraintViolationException) {
            $this->discardPhoto($photoPath);
            throw ValidationException::withMessages(['client_uuid' => 'Este envío ya fue registrado.']);
        } catch (Throwable $e) {
            $this->discardPhoto($photoPath);
            throw $e;
        }
    }

    /** La foto se guarda antes de confirmar la visita; si algo falla no debe quedar huérfana en el disco. */
    private function discardPhoto(?string $path): void
    {
        if ($path) {
            Storage::delete($path);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function storeVisit(Ticket $ticket, array $data, User $by, ?UploadedFile $photo, ?string &$photoPath): Visit
    {
        $condition = AtmCondition::findOrFail($data['atm_condition_id']);
        $finishedAt = $this->clientTime(
            $ticket,
            isset($data['finished_at']) ? CarbonImmutable::parse($data['finished_at']) : null,
            (bool) ($data['queued'] ?? false),
            $this->lastEventAt($ticket),
        );
        // La llegada es la hora en que marcó "Llegué al sitio"; si no lo marcó, la del cierre.
        $arrivedAt = $ticket->events()
            ->where('to_status', TicketStatus::OnSite->value)
            ->where('created_at', '<=', $finishedAt)
            ->reorder()->latest('created_at')->value('created_at');
        $parts = collect($data['parts'] ?? [])->filter(fn ($p) => ($p['quantity'] ?? 0) > 0);

        $visit = $ticket->visits()->create([
            'client_uuid' => $data['client_uuid'] ?? null,
            'technician_id' => $by->role === Role::Technician ? $by->id : ($ticket->assigned_to ?? $by->id),
            'fault_type_id' => $data['fault_type_id'] ?? $ticket->fault_type_id,
            'constancia_number' => $data['constancia_number'] ?? null,
            'visit_date' => $finishedAt,
            'arrived_at' => $arrivedAt,
            'finished_at' => $finishedAt,
            'work_done' => $data['work_done'] ?? null,
            'parts_changed' => $parts->isNotEmpty(),
            'atm_condition_id' => $condition->id,
            'constancia_path' => $photoPath = $photo ? $this->constancias->store($photo, $ticket) : null,
        ]);
        foreach ($parts as $part) {
            $visit->parts()->attach($part['part_id'], ['quantity' => $part['quantity']]);
        }

        $from = $ticket->status;
        // "Operativo" ya no cierra el ticket: queda por confirmar hasta que el administrador
        // dé la última confirmación. El reloj se detiene aquí, en la hora en que terminó el técnico.
        $resolved = $condition->resulting_ticket_status === TicketStatus::Closed;
        $to = $resolved ? TicketStatus::PendingClosure : $condition->resulting_ticket_status;
        $ticket->update(['status' => $to]
            + ($resolved ? $this->closingTimes($ticket, $finishedAt) : [])
            + (! $ticket->responded_at ? ['responded_at' => $arrivedAt ? CarbonImmutable::parse($arrivedAt) : $finishedAt] : []));
        $this->log($ticket, $by, $from, $to, "Atención registrada: {$condition->name}", $finishedAt);

        if ($this->notify) {
            defer(fn () => $resolved
                ? $this->push->closurePending($ticket->fresh(), $by, $finishedAt)
                : $this->push->visitPending($ticket->fresh(), $by, $condition, $finishedAt));
        }

        return $visit;
    }

    /**
     * Última confirmación del administrador: el ticket queda concluido. Las horas del
     * cierre (closed_at, duración) son las que informó el técnico; aquí se anota quién
     * confirmó y cuándo.
     */
    public function confirmClosure(Ticket $ticket, User $by, ?string $note = null): Ticket
    {
        $this->ensurePendingClosure($ticket);

        $ticket->update(['status' => TicketStatus::Closed, 'confirmed_at' => CarbonImmutable::now(), 'confirmed_by' => $by->id]);
        $this->log($ticket, $by, TicketStatus::PendingClosure, TicketStatus::Closed, 'Cierre confirmado'.($note ? ": {$note}" : ''));

        if ($this->notify) {
            defer(fn () => $this->push->ticketClosed($ticket->fresh(), $by));
        }

        return $ticket;
    }

    /**
     * El administrador no da por bueno el cierre: el ticket vuelve al técnico, el reloj
     * sigue corriendo desde la apertura y el motivo queda en el historial.
     */
    public function rejectClosure(Ticket $ticket, User $by, string $reason): Ticket
    {
        $this->ensurePendingClosure($ticket);

        $to = $ticket->assigned_to ? TicketStatus::Assigned : TicketStatus::Open;
        $ticket->update(['status' => $to, 'closed_at' => null, 'duration_ms' => null]);
        $this->log($ticket, $by, TicketStatus::PendingClosure, $to, "Cierre devuelto: {$reason}");

        if ($this->notify) {
            defer(fn () => $this->push->closureRejected($ticket->fresh(), $by, $reason));
        }

        return $ticket;
    }

    private function ensurePendingClosure(Ticket $ticket): void
    {
        if ($ticket->status !== TicketStatus::PendingClosure) {
            throw ValidationException::withMessages(['status' => 'Este ticket no está esperando la confirmación del cierre.']);
        }
    }

    /**
     * Edita datos del ticket y deja constancia de cada cambio en el historial.
     *
     * @param  array{fault_type_id?: int|null, description?: string|null, bank_reference?: string|null}  $data
     */
    public function edit(Ticket $ticket, array $data, User $by): Ticket
    {
        $labels = ['priority' => 'Prioridad', 'fault_type_id' => 'Falla', 'description' => 'Detalle', 'bank_reference' => 'Caso del banco'];
        $sla = app(SlaService::class);
        $zoneBefore = $sla->zoneOf($ticket);
        if (array_key_exists('sla_zone', $data)) {
            $data['sla_zone'] = $sla->chosenZone($data['sla_zone'], $ticket);
        }
        $ticket->fill($data);
        $changes = [];
        foreach (array_keys($ticket->getDirty()) as $field) {
            $changes[] = match ($field) {
                'priority' => 'Prioridad: '.($ticket->getOriginal('priority') ?? Priority::Medium)->label().' → '.$ticket->priority->label(),
                'sla_zone' => 'Tipo de SLA: '.$zoneBefore->label().' → '.$sla->zoneOf($ticket)->label(),
                'fault_type_id' => 'Falla: '.(FaultType::find($ticket->getOriginal('fault_type_id'))?->name ?? '—').' → '.(FaultType::find($ticket->fault_type_id)?->name ?? '—'),
                default => ($labels[$field] ?? $field).' actualizado',
            };
        }
        if ($changes) {
            $ticket->save();
            $this->log($ticket, $by, null, null, 'Editado: '.implode(' · ', $changes));
        }

        return $ticket;
    }

    /** Comentario de seguimiento; avisa al técnico, a quien lo abrió y a los seguidores. */
    public function comment(Ticket $ticket, string $body, User $by): TicketComment
    {
        $comment = $ticket->comments()->create([
            'user_id' => $by->id,
            'body' => $body,
            'created_at' => CarbonImmutable::now(),
        ]);
        if ($this->notify) {
            defer(fn () => $this->push->commented($ticket->fresh(), $comment->fresh('user')));
        }

        return $comment;
    }

    /** @return array{closed_at: CarbonImmutable, duration_ms: int} */
    private function closingTimes(Ticket $ticket, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();

        return [
            'closed_at' => $at,
            'duration_ms' => max(0, (int) round($ticket->opened_at->diffInMilliseconds($at))),
        ];
    }

    /**
     * Hora que se registra para un cambio hecho por el técnico. Con señal vale la del
     * servidor: la del teléfono solo se acepta si prácticamente coincide. Si el cambio
     * estuvo guardado sin señal ($queued) se acepta la del teléfono, acotada, y queda
     * a la vista la hora en que realmente llegó (received_at), para que nadie pueda
     * declarar una hora anterior sin que se note.
     */
    private function clientTime(Ticket $ticket, ?CarbonImmutable $at, bool $queued, ?CarbonInterface $notBefore = null): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $at = $this->clampToCycle($ticket, $at, $notBefore);

        return ! $queued && $at->diffInSeconds($now, true) > self::CLOCK_TOLERANCE_SECONDS ? $now : $at;
    }

    /**
     * Hora informada por el teléfono (suele venir en UTC), llevada a la zona
     * horaria de la app y acotada entre la apertura del ticket y ahora.
     */
    private function clampToCycle(Ticket $ticket, ?CarbonImmutable $at, ?CarbonInterface $notBefore = null): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        if ($at === null || $at->greaterThan($now)) {
            return $now;
        }
        $at = $at->setTimezone(config('app.timezone'));
        // Nunca antes de la apertura, del evento anterior ni de MAX_BACKDATE_HOURS atrás:
        // así nadie puede retrodatar para aparentar que cumplió el SLA.
        $floor = CarbonImmutable::instance($ticket->opened_at)->max($now->subHours(self::MAX_BACKDATE_HOURS));
        if ($notBefore) {
            $floor = $floor->max(CarbonImmutable::instance($notBefore));
        }
        $floor = $floor->min($now);

        return $at->lessThan($floor) ? $floor : $at;
    }

    /** Hora del último cambio de estado del ticket (asignación, en tránsito, en sitio…). */
    private function lastEventAt(Ticket $ticket): ?CarbonImmutable
    {
        $at = $ticket->events()->whereNotNull('to_status')->reorder()->latest('created_at')->value('created_at');

        return $at ? CarbonImmutable::parse($at) : null;
    }

    private function log(Ticket $ticket, ?User $by, ?TicketStatus $from, ?TicketStatus $to, ?string $note, ?CarbonImmutable $at = null): void
    {
        $ticket->events()->create([
            'user_id' => $by?->id,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'note' => $note,
            'created_at' => $at ?? CarbonImmutable::now(),
            'received_at' => CarbonImmutable::now(),
        ]);
    }
}
