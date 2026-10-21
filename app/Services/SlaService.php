<?php

namespace App\Services;

use App\Enums\SlaLevel;
use App\Enums\TicketStatus;
use App\Enums\Zone;
use App\Models\Atm;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Semáforo del SLA por ticket, según la zona del cajero y contando solo el
 * horario bancario (ver BusinessCalendar):
 * - urbana: verde hasta 4 h hábiles, naranja hasta 8 h, crítica después;
 * - extraurbana: verde hasta 1 día hábil, naranja hasta 2, crítica después.
 * El reloj corre de la apertura hasta que el técnico reporta el cierre y no se
 * detiene mientras el ticket espera repuesto. Los históricos importados y los
 * anulados no se miden.
 *
 * Cada ticket guarda cuándo pasa a naranja y a crítica (sla_orange_at /
 * sla_critical_at) para poder filtrar en la base de datos.
 */
class SlaService
{
    /** @var Collection<string, SlaPolicy>|null */
    private ?Collection $policies = null;

    private ?BusinessCalendar $calendar = null;

    /** @return Collection<string, SlaPolicy> */
    public function policies(): Collection
    {
        return $this->policies ??= SlaPolicy::all()->keyBy(fn (SlaPolicy $p) => $p->zone->value);
    }

    public function calendar(): BusinessCalendar
    {
        return $this->calendar ??= BusinessCalendar::fromSettings();
    }

    public function forget(): void
    {
        $this->policies = null;
        $this->calendar = null;
    }

    /**
     * Minutos hábiles hasta naranja y hasta crítica para una zona.
     *
     * @return array{0: int, 1: int}|null
     */
    public function limits(Zone $zone): ?array
    {
        $policy = $this->policies()->get($zone->value);
        if (! $policy) {
            return null;
        }
        $unit = $policy->unit === SlaPolicy::DAYS ? $this->calendar()->dayMinutes() : 60;

        return [(int) round($policy->green * $unit), (int) round($policy->orange * $unit)];
    }

    public function zoneOf(Ticket $ticket): Zone
    {
        $atm = $ticket->relationLoaded('atm') && $ticket->atm?->id === $ticket->atm_id
            ? $ticket->atm
            : Atm::withoutGlobalScopes()->find($ticket->atm_id);

        return Zone::tryFrom((string) $atm?->zone) ?? Zone::Urban;
    }

    /** Calcula (sin guardar) cuándo pasa el ticket a naranja y a crítica. */
    public function stamp(Ticket $ticket): void
    {
        $limits = $ticket->source === 'import' || ! $ticket->opened_at ? null : $this->limits($this->zoneOf($ticket));
        $ticket->sla_orange_at = $limits ? $this->calendar()->addMinutes($ticket->opened_at, $limits[0]) : null;
        $ticket->sla_critical_at = $limits ? $this->calendar()->addMinutes($ticket->opened_at, $limits[1]) : null;
    }

    /**
     * Vuelve a calcular los vencimientos tras cambiar metas, horario, feriados o la
     * zona de un cajero. Por defecto solo los tickets en curso: los cerrados
     * conservan el resultado con el que se cerraron.
     */
    public function restamp(bool $onlyRunning = true, ?int $atmId = null): int
    {
        $this->forget();
        $count = 0;
        Ticket::withoutGlobalScopes()
            ->where('source', '!=', 'import')
            ->when($onlyRunning, fn ($q) => $q->whereNotIn('status', TicketStatus::stopped()))
            ->when($atmId, fn ($q, $id) => $q->where('atm_id', $id))
            ->with(['atm' => fn ($q) => $q->withoutGlobalScopes()])
            ->chunkById(200, function ($tickets) use (&$count) {
                foreach ($tickets as $ticket) {
                    $this->stamp($ticket);
                    if ($ticket->isDirty()) {
                        $ticket->saveQuietly();
                        $count++;
                    }
                }
            });

        return $count;
    }

    /** Color del ticket ahora (o al cerrar); null si no se mide. */
    public function level(Ticket $ticket, ?CarbonInterface $now = null): ?SlaLevel
    {
        $end = $this->end($ticket, $now);
        if (! $end || ! $ticket->sla_orange_at || ! $ticket->sla_critical_at) {
            return null;
        }

        return match (true) {
            $end->lessThanOrEqualTo($ticket->sla_orange_at) => SlaLevel::Green,
            $end->lessThanOrEqualTo($ticket->sla_critical_at) => SlaLevel::Orange,
            default => SlaLevel::Critical,
        };
    }

    /** @return array<string, mixed> */
    public function evaluate(Ticket $ticket, ?CarbonInterface $now = null): array
    {
        $zone = $this->zoneOf($ticket);
        $policy = $this->policies()->get($zone->value);
        $limits = $this->limits($zone);
        $level = $this->level($ticket, $now);
        $end = $this->end($ticket, $now);

        return [
            'level' => $level?->value,
            'label' => $level?->label(),
            'zone' => $zone->value,
            'zone_label' => $zone->label(),
            'running' => $level !== null && ! $ticket->status->clockStopped(),
            'elapsed_minutes' => $level ? $this->calendar()->minutesBetween($ticket->opened_at, $end) : null,
            'day_minutes' => $this->calendar()->dayMinutes(),
            'green_minutes' => $limits[0] ?? null,
            'orange_minutes' => $limits[1] ?? null,
            'unit' => $policy?->unit,
            'green' => $policy?->green,
            'orange' => $policy?->orange,
            'orange_at' => $level ? $ticket->sla_orange_at->toIso8601String() : null,
            'critical_at' => $level ? $ticket->sla_critical_at->toIso8601String() : null,
        ];
    }

    /** Hasta dónde corre el reloj: el cierre reportado o ahora; null si no se mide. */
    private function end(Ticket $ticket, ?CarbonInterface $now): ?CarbonInterface
    {
        if ($ticket->source === 'import' || $ticket->status === TicketStatus::Cancelled) {
            return null;
        }
        if (in_array($ticket->status, [TicketStatus::Closed, TicketStatus::PendingClosure], true)) {
            return $ticket->closed_at;
        }

        return $now ?? now();
    }
}
