<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Enums\SlaLevel;
use App\Enums\TicketStatus;
use App\Enums\Zone;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visit;
use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    public function __construct(private TicketService $tickets) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        // Filtros siempre como texto simple: un valor en forma de lista provocaba errores 500.
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:200'],
            'open' => ['nullable', 'string', 'max:5'],
            'history' => ['nullable', 'string', 'max:5'],
            'sla' => ['nullable', 'string', 'max:20'],
            'sort' => ['nullable', 'string', 'max:20'],
            'bank_id' => ['nullable', 'integer'],
            'technician_id' => ['nullable', 'integer'],
            'atm_id' => ['nullable', 'integer'],
            'closed_from' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer'],
        ]);
        $user = $request->user();
        // % y _ se buscan como texto, no como comodines.
        $term = addcslashes(trim((string) $request->query('q')), '%_\\');
        $statuses = array_filter(explode(',', (string) $request->query('status')));

        $tickets = Ticket::query()
            ->with(['atm.state', 'bank', 'faultType', 'technician'])
            // Técnico: sus tickets asignados; con ?history=1, todo lo que atendió (historial).
            ->when($user->role === Role::Technician && ! $request->boolean('history'), fn ($q) => $q->where('assigned_to', $user->id))
            ->when($user->role === Role::Technician && $request->boolean('history'), fn ($q) => $q->forTechnician($user))
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->when($request->query('open'), fn ($q) => $q->whereNotIn('status', [TicketStatus::Closed, TicketStatus::Cancelled]))
            ->when($request->query('bank_id'), fn ($q, $v) => $q->where('bank_id', $v))
            ->when($request->query('technician_id'), fn ($q, $v) => $q->where('assigned_to', $v))
            ->when($request->query('atm_id'), fn ($q, $v) => $q->where('atm_id', $v))
            ->when($request->query('closed_from'), fn ($q, $v) => $q->whereDate('closed_at', '>=', $v))
            ->when(SlaLevel::tryFrom((string) $request->query('sla')), fn ($q, $level) => $q->slaLevel($level))
            ->when($request->query('from'), fn ($q, $v) => $q->whereDate('opened_at', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->whereDate('opened_at', '<=', $v))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('number', 'like', "%{$term}%")
                ->orWhere('bank_reference', 'like', "%{$term}%")
                ->orWhereHas('atm', fn ($a) => $a->where('atm_number', 'like', "%{$term}%")->orWhere('agency_name', 'like', "%{$term}%"))))
            ->when($request->boolean('history'), fn ($q) => $q->orderByRaw('coalesce(closed_at, opened_at) desc'))
            // Los más urgentes primero: los que antes pasan (o pasaron) a crítica.
            ->when($request->query('sort') === 'sla', fn ($q) => $q->orderByRaw('sla_critical_at is null')->orderBy('sla_critical_at'))
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->paginate(max(1, min(200, (int) $request->query('per_page', 25))));

        return TicketResource::collection($tickets);
    }

    public function store(StoreTicketRequest $request): TicketResource
    {
        $ticket = $this->tickets->open($request->validated(), $request->user());

        return $this->detail($ticket);
    }

    public function show(Ticket $ticket): TicketResource
    {
        Gate::authorize('view', $ticket);

        return $this->detail($ticket);
    }

    public function assign(Request $request, Ticket $ticket): TicketResource
    {
        Gate::authorize('manage', $ticket);
        $data = $request->validate(['technician_id' => ['required', 'integer', 'exists:users,id']]);

        $this->tickets->assign($ticket, User::findOrFail($data['technician_id']), $request->user());

        return $this->detail($ticket);
    }

    public function status(Request $request, Ticket $ticket): TicketResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([TicketStatus::InTransit->value, TicketStatus::OnSite->value, TicketStatus::Cancelled->value])],
            'note' => ['nullable', 'string', 'max:255', 'required_if:status,'.TicketStatus::Cancelled->value],
            'at' => ['nullable', 'date'],
            'queued' => ['sometimes', 'boolean'],
        ]);
        $to = TicketStatus::from($data['status']);
        Gate::authorize($to === TicketStatus::Cancelled ? 'manage' : 'work', $ticket);

        $this->tickets->transition($ticket, $to, $request->user(), $data['note'] ?? null, isset($data['at']) ? CarbonImmutable::parse($data['at']) : null, (bool) ($data['queued'] ?? false));

        return $this->detail($ticket);
    }

    /** Última confirmación del administrador sobre el cierre informado por el técnico. */
    public function confirm(Request $request, Ticket $ticket): TicketResource
    {
        Gate::authorize('confirm', $ticket);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        $this->tickets->confirmClosure($ticket, $request->user(), $data['note'] ?? null);

        return $this->detail($ticket);
    }

    /** El administrador devuelve el ticket al técnico indicando el motivo. */
    public function reject(Request $request, Ticket $ticket): TicketResource
    {
        Gate::authorize('confirm', $ticket);
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:255']]);

        $this->tickets->rejectClosure($ticket, $request->user(), trim($data['note']));

        return $this->detail($ticket);
    }

    /** Resumen del técnico para su historial: este mes y total. */
    public function technicianSummary(Request $request): JsonResponse
    {
        $user = $request->user();
        $monthStart = now()->startOfMonth();
        $visits = Visit::where('technician_id', $user->id);
        $monthVisits = (clone $visits)->where('visit_date', '>=', $monthStart->toDateString())->with('parts')->get();

        return response()->json([
            'month' => [
                'visits' => $monthVisits->count(),
                'closed' => Ticket::forTechnician($user)->where('status', TicketStatus::Closed)->where('closed_at', '>=', $monthStart)->count(),
                'parts_units' => (int) $monthVisits->flatMap->parts->sum('pivot.quantity'),
            ],
            'total' => [
                'tickets' => Ticket::forTechnician($user)->count(),
                'visits' => $visits->count(),
            ],
        ]);
    }

    /** Editar falla, tipo de SLA, detalle o caso del banco (queda en el historial). */
    public function update(Request $request, Ticket $ticket): TicketResource
    {
        Gate::authorize('manage', $ticket);
        $data = $request->validate([
            'fault_type_id' => ['sometimes', 'nullable', 'integer', 'exists:fault_types,id'],
            'sla_zone' => ['sometimes', 'nullable', Rule::enum(Zone::class)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'bank_reference' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);

        $this->tickets->edit($ticket, $data, $request->user());

        return $this->detail($ticket);
    }

    public function comment(Request $request, Ticket $ticket): TicketResource
    {
        Gate::authorize('view', $ticket);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $this->tickets->comment($ticket, trim($data['body']), $request->user());
        // Quien comenta queda siguiendo el ticket, como en JIRA (si todavía le corresponde).
        if ($request->user()->can('work', $ticket)) {
            $ticket->watchers()->syncWithoutDetaching([$request->user()->id]);
        }

        return $this->detail($ticket);
    }

    public function watch(Request $request, Ticket $ticket): TicketResource|JsonResponse
    {
        // Dejar de seguir siempre se puede, aunque ya no pueda ver el ticket.
        if ($request->isMethod('delete')) {
            $ticket->watchers()->detach($request->user()->id);

            return $request->user()->can('view', $ticket) ? $this->detail($ticket) : response()->json(['ok' => true]);
        }
        // Seguir, solo si aún le corresponde el ticket.
        Gate::authorize('work', $ticket);
        $ticket->watchers()->syncWithoutDetaching([$request->user()->id]);

        return $this->detail($ticket);
    }

    private function detail(Ticket $ticket): TicketResource
    {
        return new TicketResource($ticket->load([
            'atm.state', 'atm.bank', 'bank', 'faultType', 'technician', 'opener', 'confirmer',
            'visits.parts', 'visits.technician', 'visits.faultType', 'visits.atmCondition',
            'events.user', 'comments.user', 'watchers',
        ]));
    }
}
