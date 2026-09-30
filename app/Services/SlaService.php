<?php

namespace App\Services;

use App\Enums\Priority;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Cumplimiento de SLA por ticket. Dos metas según la prioridad:
 * - llegada: de la apertura a la primera llegada al sitio;
 * - resolución: de la apertura al cierre.
 * El reloj no se detiene mientras el ticket espera repuesto (acuerdo con el cliente).
 * Estados: met (cumplido), breached (vencido), at_risk (≥ 80 % del tiempo, en curso),
 * on_track (en curso), none (sin meta o histórico sin horas).
 */
class SlaService
{
    public const AT_RISK_RATIO = 0.8;

    /** @var Collection<string, SlaPolicy>|null */
    private ?Collection $policies = null;

    /** @return Collection<string, SlaPolicy> */
    public function policies(): Collection
    {
        return $this->policies ??= SlaPolicy::all()->keyBy(fn (SlaPolicy $p) => $p->priority->value);
    }

    public function forget(): void
    {
        $this->policies = null;
    }

    /**
     * @return array{response: array<string, mixed>, resolution: array<string, mixed>}
     */
    public function evaluate(Ticket $ticket, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $policy = $this->policies()->get(($ticket->priority ?? Priority::Medium)->value);
        $imported = $ticket->source === 'import';
        $finished = $ticket->status->isFinal();

        return [
            'response' => $this->clock(
                $imported ? null : $policy?->response_minutes,
                $ticket->opened_at,
                $ticket->responded_at,
                // Si se cerró o anuló sin marcar llegada, el reloj de llegada termina ahí.
                $finished && ! $ticket->responded_at ? $ticket->closed_at : null,
                $now,
            ),
            'resolution' => $this->clock(
                $imported ? null : $policy?->resolution_minutes,
                $ticket->opened_at,
                $ticket->status->value === 'concluido' ? $ticket->closed_at : null,
                $ticket->status->value === 'anulado' ? $ticket->closed_at : null,
                $now,
            ),
        ];
    }

    /** @return array{target_minutes: int|null, elapsed_minutes: int, due_at: string|null, status: string} */
    private function clock(?int $target, CarbonInterface $start, ?CarbonInterface $done, ?CarbonInterface $stoppedWithout, CarbonInterface $now): array
    {
        $end = $done ?? $stoppedWithout ?? $now;
        $elapsed = (int) floor($start->diffInSeconds($end) / 60);

        if (! $target) {
            $status = 'none';
        } elseif ($done) {
            $status = $elapsed <= $target ? 'met' : 'breached';
        } elseif ($stoppedWithout) {
            $status = 'none'; // anulado o cerrado sin ese hito: no cuenta
        } else {
            $status = $elapsed > $target ? 'breached' : ($elapsed >= $target * self::AT_RISK_RATIO ? 'at_risk' : 'on_track');
        }

        return [
            'target_minutes' => $target,
            'elapsed_minutes' => max(0, $elapsed),
            'due_at' => $target ? $start->copy()->addMinutes($target)->toIso8601String() : null,
            'status' => $status,
        ];
    }
}
