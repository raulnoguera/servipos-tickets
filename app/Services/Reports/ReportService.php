<?php

namespace App\Services\Reports;

use App\Enums\TicketStatus;
use App\Models\Atm;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visit;
use App\Services\SlaService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Reportes y métricas. Los tickets del histórico importado cuentan en los
 * totales pero no en los tiempos (no tienen horas).
 *
 * Filtros comunes: from, to (fechas), bank_id, state_id (estado del cajero), technician_id.
 */
class ReportService
{
    /** Días para considerar que un cajero reincide en falla. */
    public const REINCIDENCE_DAYS = 30;

    public function __construct(private SlaService $sla) {}

    /**
     * @param  array{from?: string|null, to?: string|null, bank_id?: int|string|null, state_id?: int|string|null, technician_id?: int|string|null}  $input
     * @return array{from: CarbonImmutable, to: CarbonImmutable, bank_id: ?int, state_id: ?int, technician_id: ?int}
     */
    public function filters(array $input): array
    {
        $from = ! empty($input['from']) ? CarbonImmutable::parse($input['from'])->startOfDay() : CarbonImmutable::now()->startOfMonth();
        $to = ! empty($input['to']) ? CarbonImmutable::parse($input['to'])->endOfDay() : CarbonImmutable::now()->endOfDay();

        return [
            'from' => $from,
            'to' => $to->lessThan($from) ? $from->endOfDay() : $to,
            'bank_id' => ! empty($input['bank_id']) ? (int) $input['bank_id'] : null,
            'state_id' => ! empty($input['state_id']) ? (int) $input['state_id'] : null,
            'technician_id' => ! empty($input['technician_id']) ? (int) $input['technician_id'] : null,
        ];
    }

    /* ---------------------------------------------------------------- Resumen */

    /** @return array<string, mixed> */
    public function summary(array $f): array
    {
        $opened = $this->ticketsOpened($f)->with(['visits', 'atm'])->get();
        $closed = $this->scoped($f)->where('status', TicketStatus::Closed)
            ->whereBetween('closed_at', [$f['from'], $f['to']])->with('visits')->get();
        $backlog = $this->scoped($f, withDates: false)->whereNotIn('status', [TicketStatus::Closed, TicketStatus::Cancelled])->get();

        $timed = $closed->where('source', '!=', 'import');
        $slaRows = $opened->where('source', '!=', 'import')->map(fn (Ticket $t) => $this->sla->evaluate($t));

        return [
            'kpis' => [
                'opened' => $opened->count(),
                'closed' => $closed->count(),
                'backlog' => $backlog->count(),
                'mttr_ms' => $this->avg($timed->pluck('duration_ms')),
                'mtta_ms' => $this->avg($opened->where('source', '!=', 'import')->filter(fn ($t) => $t->responded_at)
                    ->map(fn (Ticket $t) => $t->opened_at->diffInMilliseconds($t->responded_at))),
                'first_visit_rate' => $this->rate($closed->filter(fn (Ticket $t) => $t->visits->count() === 1)->count(), $closed->count()),
                'sla_resolution_rate' => $this->slaRate($slaRows, 'resolution'),
                'sla_response_rate' => $this->slaRate($slaRows, 'response'),
                'sla_breached' => $slaRows->filter(fn ($s) => $s['resolution']['status'] === 'breached' || $s['response']['status'] === 'breached')->count(),
                'reincidence_rate' => $this->rate($this->reincidentCount($opened), $opened->count()),
            ],
            'backlog_aging' => $this->aging($backlog),
            'by_status' => $opened->countBy(fn (Ticket $t) => $t->status->label())->sortDesc()->map(fn ($n, $k) => ['name' => $k, 'total' => $n])->values(),
            'by_priority' => $opened->countBy(fn (Ticket $t) => $t->priority?->label() ?? 'Media')->map(fn ($n, $k) => ['name' => $k, 'total' => $n])->values(),
            'weekly' => $this->weekly($opened, $closed, $f),
        ];
    }

    /* ---------------------------------------------------------------- Cajeros */

    /** @return Collection<int, array<string, mixed>> */
    public function atms(array $f): Collection
    {
        $tickets = $this->ticketsOpened($f)->with(['atm.state', 'atm.bank', 'visits.parts', 'faultType'])->get();

        return $tickets->groupBy('atm_id')->map(function (Collection $group) {
            $atm = $group->first()->atm;
            $timed = $group->where('source', '!=', 'import')->where('status', TicketStatus::Closed);
            $parts = $group->flatMap->visits->flatMap->parts;

            return [
                'atm_id' => $atm->id,
                'atm_number' => $atm->atm_number,
                'agency_name' => $atm->agency_name,
                'agency_code' => $atm->agency_code,
                'state' => $atm->state?->name,
                'bank' => $atm->bank?->name,
                'model' => $atm->model,
                'tickets' => $group->count(),
                'reincidences' => max(0, $group->count() - 1),
                'open' => $group->filter(fn (Ticket $t) => ! $t->status->isFinal())->count(),
                'visits' => $group->sum(fn (Ticket $t) => $t->visits->count()),
                'parts_units' => (int) $parts->sum('pivot.quantity'),
                'top_fault' => $group->countBy(fn (Ticket $t) => $t->faultType?->name ?? 'Sin clasificar')->sortDesc()->keys()->first(),
                'mttr_ms' => $this->avg($timed->pluck('duration_ms')),
                'last_ticket_at' => $group->max('opened_at')?->toIso8601String(),
            ];
        })->sortByDesc(fn ($row) => [$row['tickets'], $row['parts_units']])->values();
    }

    /** Hoja de vida del cajero: todos sus tickets, repuestos y fallas. @return array<string, mixed> */
    public function atmHistory(Atm $atm): array
    {
        $tickets = $atm->tickets()->with(['faultType', 'technician', 'visits.parts', 'visits.atmCondition'])
            ->orderByDesc('opened_at')->get();
        $timed = $tickets->where('source', '!=', 'import')->where('status', TicketStatus::Closed);
        $parts = $tickets->flatMap->visits->flatMap->parts;

        return [
            'stats' => [
                'tickets' => $tickets->count(),
                'open' => $tickets->filter(fn (Ticket $t) => ! $t->status->isFinal())->count(),
                'last_90_days' => $tickets->where('opened_at', '>=', now()->subDays(90))->count(),
                'visits' => $tickets->sum(fn (Ticket $t) => $t->visits->count()),
                'parts_units' => (int) $parts->sum('pivot.quantity'),
                'mttr_ms' => $this->avg($timed->pluck('duration_ms')),
            ],
            'faults' => $tickets->countBy(fn (Ticket $t) => $t->faultType?->name ?? 'Sin clasificar')->sortDesc()
                ->map(fn ($n, $name) => ['name' => $name, 'total' => $n])->values(),
            'parts' => $parts->groupBy('name')->map(fn ($g, $name) => ['name' => $name, 'quantity' => (int) $g->sum('pivot.quantity')])
                ->sortByDesc('quantity')->values(),
            'tickets' => $tickets->take(100)->map(fn (Ticket $t) => [
                'id' => $t->id,
                'number' => $t->number,
                'status' => $t->status->value,
                'priority' => $t->priority?->value,
                'opened_at' => $t->opened_at->toIso8601String(),
                'closed_at' => $t->closed_at?->toIso8601String(),
                'duration_ms' => $t->duration_ms,
                'fault_type' => $t->faultType?->name,
                'technician' => $t->technician?->name,
                'visits' => $t->visits->count(),
                'parts' => $t->visits->flatMap->parts->map(fn ($p) => "{$p->name} x{$p->pivot->quantity}")->implode(', '),
            ])->values(),
        ];
    }

    /* ---------------------------------------------------------------- Técnicos */

    /** @return Collection<int, array<string, mixed>> */
    public function technicians(array $f): Collection
    {
        $opened = $this->ticketsOpened($f)->whereNotNull('assigned_to')->with(['visits.parts'])->get()->groupBy('assigned_to');
        $closed = $this->scoped($f)->where('status', TicketStatus::Closed)->whereBetween('closed_at', [$f['from'], $f['to']])
            ->whereNotNull('assigned_to')->with('visits')->get()->groupBy('assigned_to');
        $pending = Ticket::query()->whereNotIn('status', [TicketStatus::Closed, TicketStatus::Cancelled])->whereNotNull('assigned_to')
            ->selectRaw('assigned_to, count(*) as total')->groupBy('assigned_to')->pluck('total', 'assigned_to');
        $visits = Visit::query()->whereDate('visit_date', '>=', $f['from']->toDateString())->whereDate('visit_date', '<=', $f['to']->toDateString())
            ->when($f['technician_id'], fn ($q, $id) => $q->where('technician_id', $id))
            ->with('parts')->get()->groupBy('technician_id');

        $ids = collect($opened->keys())->merge($closed->keys())->merge($pending->keys())->merge($visits->keys())->unique()->filter();

        return User::whereIn('id', $ids)->get()->map(function (User $tech) use ($opened, $closed, $pending, $visits) {
            $mine = $opened->get($tech->id, collect());
            $done = $closed->get($tech->id, collect());
            $timedDone = $done->where('source', '!=', 'import');
            $timedMine = $mine->where('source', '!=', 'import');
            $slaRows = $timedMine->map(fn (Ticket $t) => $this->sla->evaluate($t));
            $techVisits = $visits->get($tech->id, collect());

            return [
                'technician_id' => $tech->id,
                'name' => $tech->name,
                'active' => $tech->active,
                'assigned' => $mine->count(),
                'closed' => $done->count(),
                'pending_now' => (int) ($pending[$tech->id] ?? 0),
                'visits' => $techVisits->count(),
                'parts_units' => (int) $techVisits->flatMap->parts->sum('pivot.quantity'),
                'mtta_ms' => $this->avg($timedMine->filter(fn ($t) => $t->responded_at)->map(fn (Ticket $t) => $t->opened_at->diffInMilliseconds($t->responded_at))),
                'mttr_ms' => $this->avg($timedDone->pluck('duration_ms')),
                'first_visit_rate' => $this->rate($done->filter(fn (Ticket $t) => $t->visits->count() === 1)->count(), $done->count()),
                'sla_resolution_rate' => $this->slaRate($slaRows, 'resolution'),
            ];
        })->sortByDesc(fn ($r) => [$r['closed'], $r['assigned']])->values();
    }

    /* ---------------------------------------------------------------- Repuestos */

    /** @return array<string, mixed> */
    public function parts(array $f): array
    {
        $rows = $this->partRows($f['from'], $f['to'], $f);

        $trendFrom = $f['to']->subMonthsNoOverflow(5)->startOfMonth();
        $trendRows = $this->partRows($trendFrom, $f['to'], $f);
        $months = collect(range(0, 5))->map(fn ($i) => $trendFrom->addMonthsNoOverflow($i)->format('Y-m'));
        $topParts = $trendRows->groupBy('part')->map->sum('quantity')->sortDesc()->take(5)->keys();

        return [
            'total' => (int) $rows->sum('quantity'),
            'visits_with_parts' => $rows->pluck('visit_id')->unique()->count(),
            'by_part' => $this->groupSum($rows, 'part')->map(fn ($r) => $r + [
                'atms' => $rows->where('part', $r['name'])->pluck('atm_id')->unique()->count(),
            ])->values(),
            'by_model' => $rows->groupBy('model')->map(fn ($g, $model) => [
                'name' => $model,
                'quantity' => (int) $g->sum('quantity'),
                'parts' => $this->groupSum($g, 'part')->values(),
            ])->sortByDesc('quantity')->values(),
            'by_state' => $this->groupSum($rows, 'state')->values(),
            'top_atms' => $rows->groupBy('atm_id')->map(fn ($g) => [
                'atm_id' => $g->first()['atm_id'],
                'name' => $g->first()['atm_number'],
                'agency_name' => $g->first()['agency_name'],
                'quantity' => (int) $g->sum('quantity'),
            ])->sortByDesc('quantity')->take(10)->values(),
            'trend' => [
                'months' => $months,
                'series' => $topParts->map(fn ($part) => [
                    'name' => $part,
                    'values' => $months->map(fn ($m) => (int) $trendRows->where('part', $part)->where('month', $m)->sum('quantity'))->values(),
                ])->values(),
                'totals' => $months->map(fn ($m) => (int) $trendRows->where('month', $m)->sum('quantity'))->values(),
            ],
        ];
    }

    /* ---------------------------------------------------------------- Fallas */

    /** @return Collection<int, array<string, mixed>> */
    public function faults(array $f): Collection
    {
        return $this->ticketsOpened($f)->with('faultType')->get()
            ->groupBy(fn (Ticket $t) => $t->faultType?->name ?? 'Sin clasificar')
            ->map(function (Collection $g, string $name) {
                $closed = $g->where('status', TicketStatus::Closed);

                return [
                    'name' => $name,
                    'tickets' => $g->count(),
                    'closed' => $closed->count(),
                    'pending' => $g->filter(fn (Ticket $t) => ! $t->status->isFinal())->count(),
                    'close_rate' => $this->rate($closed->count(), $g->count()),
                    'mttr_ms' => $this->avg($closed->where('source', '!=', 'import')->pluck('duration_ms')),
                    'atms' => $g->pluck('atm_id')->unique()->count(),
                ];
            })->sortByDesc('tickets')->values();
    }

    /* ---------------------------------------------------------------- Ayudantes */

    /** Tickets de los filtros (banco, estado del cajero, técnico) con fecha de apertura en el período. */
    private function ticketsOpened(array $f): Builder
    {
        return $this->scoped($f)->whereBetween('opened_at', [$f['from'], $f['to']]);
    }

    private function scoped(array $f, bool $withDates = true): Builder
    {
        return Ticket::query()
            ->when($f['bank_id'], fn ($q, $id) => $q->where('bank_id', $id))
            ->when($f['state_id'], fn ($q, $id) => $q->whereHas('atm', fn ($a) => $a->where('state_id', $id)))
            ->when($f['technician_id'], fn ($q, $id) => $q->where('assigned_to', $id))
            ->where('status', '!=', TicketStatus::Cancelled);
    }

    /** Una fila por repuesto usado en cada visita del rango. @return Collection<int, array<string, mixed>> */
    private function partRows(CarbonImmutable $from, CarbonImmutable $to, array $f): Collection
    {
        return Visit::query()
            ->with(['parts', 'ticket.atm.state'])
            ->whereDate('visit_date', '>=', $from->toDateString())
            ->whereDate('visit_date', '<=', $to->toDateString())
            ->when($f['technician_id'], fn ($q, $id) => $q->where('technician_id', $id))
            ->whereHas('ticket', fn ($t) => $t
                ->when($f['bank_id'], fn ($q, $id) => $q->where('bank_id', $id))
                ->when($f['state_id'], fn ($q, $id) => $q->whereHas('atm', fn ($a) => $a->where('state_id', $id))))
            ->get()
            ->flatMap(fn (Visit $v) => $v->parts->map(fn ($p) => [
                'visit_id' => $v->id,
                'part' => $p->name,
                'quantity' => (int) $p->pivot->quantity,
                'atm_id' => $v->ticket->atm_id,
                'atm_number' => $v->ticket->atm->atm_number,
                'agency_name' => $v->ticket->atm->agency_name,
                'model' => $v->ticket->atm->model ?? 'Sin modelo',
                'state' => $v->ticket->atm->state?->name ?? 'Sin estado',
                'month' => $v->visit_date->format('Y-m'),
            ]));
    }

    /** @return Collection<int, array{name: string, quantity: int}> */
    private function groupSum(Collection $rows, string $key): Collection
    {
        return $rows->groupBy($key)->map(fn ($g, $name) => ['name' => $name, 'quantity' => (int) $g->sum('quantity')])->sortByDesc('quantity');
    }

    private function reincidentCount(Collection $tickets): int
    {
        if ($tickets->isEmpty()) {
            return 0;
        }
        $history = Ticket::whereIn('atm_id', $tickets->pluck('atm_id')->unique())
            ->where('opened_at', '>=', $tickets->min('opened_at')->subDays(self::REINCIDENCE_DAYS))
            ->get(['id', 'atm_id', 'opened_at'])->groupBy('atm_id');

        return $tickets->filter(fn (Ticket $t) => $history->get($t->atm_id, collect())->contains(fn ($o) => $o->id !== $t->id
            && $o->opened_at->lessThan($t->opened_at)
            && $o->opened_at->greaterThanOrEqualTo($t->opened_at->subDays(self::REINCIDENCE_DAYS))))->count();
    }

    /** @return list<array{bucket: string, total: int}> */
    private function aging(Collection $backlog): array
    {
        $buckets = ['Menos de 4 h' => 4, '4 a 24 h' => 24, '1 a 3 días' => 72, 'Más de 3 días' => PHP_INT_MAX];
        $counts = array_fill_keys(array_keys($buckets), 0);
        foreach ($backlog as $t) {
            $hours = $t->opened_at->diffInHours(now());
            foreach ($buckets as $label => $max) {
                if ($hours < $max) {
                    $counts[$label]++;
                    break;
                }
            }
        }

        return collect($counts)->map(fn ($n, $label) => ['bucket' => $label, 'total' => $n])->values()->all();
    }

    /** Abiertos y concluidos por semana del período. @return list<array{week: string, opened: int, closed: int}> */
    private function weekly(Collection $opened, Collection $closed, array $f): array
    {
        $weeks = [];
        for ($d = $f['from']->startOfWeek(); $d->lessThanOrEqualTo($f['to']); $d = $d->addWeek()) {
            $weeks[$d->toDateString()] = ['week' => $d->toDateString(), 'opened' => 0, 'closed' => 0];
        }
        foreach ($opened as $t) {
            $key = $t->opened_at->startOfWeek()->toDateString();
            isset($weeks[$key]) && $weeks[$key]['opened']++;
        }
        foreach ($closed as $t) {
            $key = $t->closed_at->startOfWeek()->toDateString();
            isset($weeks[$key]) && $weeks[$key]['closed']++;
        }

        return array_values($weeks);
    }

    private function slaRate(Collection $rows, string $kind): ?float
    {
        $evaluated = $rows->filter(fn ($s) => in_array($s[$kind]['status'], ['met', 'breached'], true));

        return $this->rate($evaluated->where("{$kind}.status", 'met')->count(), $evaluated->count());
    }

    private function avg(Collection $values): ?int
    {
        $values = $values->filter(fn ($v) => $v !== null);

        return $values->isEmpty() ? null : (int) round($values->avg());
    }

    private function rate(int $part, int $total): ?float
    {
        return $total ? round($part * 100 / $total, 1) : null;
    }
}
