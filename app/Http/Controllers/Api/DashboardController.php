<?php

namespace App\Http\Controllers\Api;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Monitor de estatus operativo (RF-4.1). */
class DashboardController extends Controller
{
    private const ACTIVE = [TicketStatus::Open, TicketStatus::Assigned, TicketStatus::InTransit, TicketStatus::OnSite];

    public function __invoke(Request $request): JsonResponse
    {
        $period = in_array($request->query('period'), ['day', 'week', 'month'], true) ? $request->query('period') : 'month';
        $now = CarbonImmutable::now();
        $from = match ($period) {
            'day' => $now->startOfDay(),
            'week' => $now->startOfWeek(),
            'month' => $now->startOfMonth(),
        };

        $countByStatus = Ticket::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $count = fn (array $statuses) => (int) collect($statuses)->sum(fn (TicketStatus $s) => $countByStatus[$s->value] ?? 0);

        $closedInPeriod = Ticket::where('status', TicketStatus::Closed)->where('closed_at', '>=', $from);

        return response()->json([
            'period' => $period,
            'from' => $from->toDateString(),
            'kpis' => [
                'active' => $count(self::ACTIVE),
                'unassigned' => $count([TicketStatus::Open]),
                'in_field' => $count([TicketStatus::InTransit, TicketStatus::OnSite]),
                'pending_part' => $count([TicketStatus::PendingPart]),
                'pending_validation' => $count([TicketStatus::PendingValidation]),
                'opened' => Ticket::where('opened_at', '>=', $from)->count(),
                'closed' => (clone $closedInPeriod)->count(),
                'avg_duration_ms' => (int) round((float) (clone $closedInPeriod)->whereNotNull('duration_ms')->avg('duration_ms')),
                'sla_breached' => Ticket::whereNotIn('status', [TicketStatus::Closed, TicketStatus::Cancelled])->sla('breached')->count(),
                'sla_at_risk' => Ticket::whereNotIn('status', [TicketStatus::Closed, TicketStatus::Cancelled])->sla('at_risk')->whereNot(fn ($q) => $q->sla('breached'))->count(),
            ],
            'daily' => $this->daily($now),
            'by_fault' => $this->byFault($from),
            'top_parts' => $this->topParts($from),
            'technicians' => $this->technicians($from),
            'recent' => TicketResource::collection(
                Ticket::with(['atm.state', 'bank', 'faultType', 'technician'])->orderByDesc('opened_at')->orderByDesc('id')->limit(8)->get()
            ),
        ]);
    }

    /** Tickets abiertos y cerrados por día, últimos 30 días. @return list<array{date: string, opened: int, closed: int}> */
    private function daily(CarbonImmutable $now): array
    {
        $start = $now->subDays(29)->startOfDay();
        $opened = Ticket::where('opened_at', '>=', $start)->pluck('opened_at')->countBy(fn ($d) => $d->toDateString());
        $closed = Ticket::where('closed_at', '>=', $start)->pluck('closed_at')->countBy(fn ($d) => $d->toDateString());

        return collect(range(0, 29))->map(function (int $i) use ($start, $opened, $closed) {
            $day = $start->addDays($i)->toDateString();

            return ['date' => $day, 'opened' => $opened[$day] ?? 0, 'closed' => $closed[$day] ?? 0];
        })->all();
    }

    /** @return list<array{name: string, total: int}> */
    private function byFault(CarbonImmutable $from): array
    {
        return Ticket::with('faultType')->where('opened_at', '>=', $from)->get()
            ->countBy(fn (Ticket $t) => $t->faultType?->name ?? 'Sin clasificar')
            ->sortDesc()->take(8)
            ->map(fn (int $total, string $name) => ['name' => $name, 'total' => $total])->values()->all();
    }

    /** @return list<array{name: string, quantity: int}> */
    private function topParts(CarbonImmutable $from): array
    {
        return Visit::with('parts')->whereDate('visit_date', '>=', $from->toDateString())->get()
            ->flatMap(fn (Visit $v) => $v->parts)
            ->groupBy('name')
            ->map(fn ($parts, string $name) => ['name' => $name, 'quantity' => (int) $parts->sum('pivot.quantity')])
            ->sortByDesc('quantity')->take(8)->values()->all();
    }

    /** @return list<array{id: int, name: string, active: int, closed: int}> */
    private function technicians(CarbonImmutable $from): array
    {
        return User::technicians()->where('active', true)
            ->withCount([
                'assignedTickets as active_tickets' => fn ($q) => $q->whereIn('status', self::ACTIVE),
                'assignedTickets as pending' => fn ($q) => $q->whereIn('status', [TicketStatus::PendingPart, TicketStatus::PendingValidation]),
                'assignedTickets as closed' => fn ($q) => $q->where('status', TicketStatus::Closed)->where('closed_at', '>=', $from),
            ])
            ->get()
            ->filter(fn (User $u) => $u->active_tickets + $u->pending + $u->closed > 0)
            ->sortByDesc(fn (User $u) => [$u->active_tickets, $u->closed])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'active' => $u->active_tickets, 'pending' => $u->pending, 'closed' => $u->closed])
            ->values()->all();
    }
}
