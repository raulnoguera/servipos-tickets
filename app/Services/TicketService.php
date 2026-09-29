<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Atm;
use App\Models\AtmCondition;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Ciclo de vida del ticket: apertura, asignación, cambios de estado y visitas. */
class TicketService
{
    public function __construct(
        private TicketNumberGenerator $numbers,
        private ConstanciaStorage $constancias,
    ) {}

    /**
     * Abre un ticket (RF-2.1/2.2) y, si se indica, lo asigna al técnico.
     *
     * @param  array{atm_id: int, fault_type_id?: int|null, description?: string|null, bank_reference?: string|null, assigned_to?: int|null}  $data
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

    public function assign(Ticket $ticket, User $technician, User $by): Ticket
    {
        if ($technician->role !== Role::Technician || ! $technician->active) {
            throw ValidationException::withMessages(['technician_id' => 'Debe seleccionar un técnico activo.']);
        }
        if ($ticket->status->isFinal()) {
            throw ValidationException::withMessages(['status' => 'El ticket ya está cerrado.']);
        }

        $from = $ticket->status;
        $ticket->update([
            'assigned_to' => $technician->id,
            'assigned_at' => $ticket->assigned_at ?? CarbonImmutable::now(),
            'status' => TicketStatus::Assigned,
        ]);
        $this->log($ticket, $by, $from, TicketStatus::Assigned, "Asignado a {$technician->name}");

        return $ticket;
    }

    /** Cambios manuales de estado: en tránsito, en sitio, anulado. */
    public function transition(Ticket $ticket, TicketStatus $to, User $by, ?string $note = null): Ticket
    {
        $allowed = [TicketStatus::InTransit, TicketStatus::OnSite, TicketStatus::Cancelled];
        if (! in_array($to, $allowed, true) || ! $ticket->status->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'status' => "No se puede pasar de \"{$ticket->status->label()}\" a \"{$to->label()}\".",
            ]);
        }

        $from = $ticket->status;
        $ticket->update(['status' => $to] + ($to === TicketStatus::Cancelled ? $this->closingTimes($ticket) : []));
        $this->log($ticket, $by, $from, $to, $note);

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
        // Reintento de una sincronización offline: devolver la visita ya creada.
        if (! empty($data['client_uuid']) && $existing = Visit::firstWhere('client_uuid', $data['client_uuid'])) {
            return $existing;
        }
        if ($ticket->status->isFinal() || $ticket->status === TicketStatus::Open) {
            throw ValidationException::withMessages([
                'status' => $ticket->status === TicketStatus::Open
                    ? 'Asigne el ticket a un técnico antes de registrar la atención.'
                    : 'El ticket ya está cerrado.',
            ]);
        }

        return DB::transaction(function () use ($ticket, $data, $by, $photo) {
            $condition = AtmCondition::findOrFail($data['atm_condition_id']);
            $finishedAt = isset($data['finished_at']) ? CarbonImmutable::parse($data['finished_at']) : CarbonImmutable::now();
            $parts = collect($data['parts'] ?? [])->filter(fn ($p) => ($p['quantity'] ?? 0) > 0);

            $visit = $ticket->visits()->create([
                'client_uuid' => $data['client_uuid'] ?? null,
                'technician_id' => $by->role === Role::Technician ? $by->id : ($ticket->assigned_to ?? $by->id),
                'fault_type_id' => $data['fault_type_id'] ?? $ticket->fault_type_id,
                'constancia_number' => $data['constancia_number'] ?? null,
                'visit_date' => $finishedAt,
                'arrived_at' => $data['arrived_at'] ?? null,
                'finished_at' => $finishedAt,
                'work_done' => $data['work_done'] ?? null,
                'parts_changed' => $parts->isNotEmpty(),
                'atm_condition_id' => $condition->id,
                'constancia_path' => $photo ? $this->constancias->store($photo, $ticket) : null,
            ]);
            foreach ($parts as $part) {
                $visit->parts()->attach($part['part_id'], ['quantity' => $part['quantity']]);
            }

            $from = $ticket->status;
            $to = $condition->resulting_ticket_status;
            $ticket->update(['status' => $to] + ($to === TicketStatus::Closed ? $this->closingTimes($ticket, $finishedAt) : []));
            $this->log($ticket, $by, $from, $to, "Atención registrada: {$condition->name}");

            return $visit;
        });
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

    private function log(Ticket $ticket, User $by, ?TicketStatus $from, TicketStatus $to, ?string $note): void
    {
        $ticket->events()->create([
            'user_id' => $by->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'note' => $note,
            'created_at' => CarbonImmutable::now(),
        ]);
    }
}
