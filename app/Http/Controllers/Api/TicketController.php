<?php

namespace App\Http\Controllers\Api;

use App\Enums\Priority;
use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    public function __construct(private TicketService $tickets) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $term = trim((string) $request->query('q'));
        $statuses = array_filter(explode(',', (string) $request->query('status')));

        $tickets = Ticket::query()
            ->with(['atm.state', 'bank', 'faultType', 'technician'])
            ->when($user->role === Role::Technician, fn ($q) => $q->where('assigned_to', $user->id))
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->when($request->query('open'), fn ($q) => $q->whereNotIn('status', [TicketStatus::Closed, TicketStatus::Cancelled]))
            ->when($request->query('bank_id'), fn ($q, $v) => $q->where('bank_id', $v))
            ->when($request->query('technician_id'), fn ($q, $v) => $q->where('assigned_to', $v))
            ->when($request->query('priority'), fn ($q, $v) => $q->whereIn('priority', explode(',', $v)))
            ->when($request->query('atm_id'), fn ($q, $v) => $q->where('atm_id', $v))
            ->when($request->query('sla') === 'breached', fn ($q) => $q->sla('breached'))
            ->when($request->query('sla') === 'at_risk', fn ($q) => $q->sla('at_risk')->whereNot(fn ($n) => $n->sla('breached')))
            ->when($request->query('from'), fn ($q, $v) => $q->whereDate('opened_at', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->whereDate('opened_at', '<=', $v))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('number', 'like', "%{$term}%")
                ->orWhere('bank_reference', 'like', "%{$term}%")
                ->orWhereHas('atm', fn ($a) => $a->where('atm_number', 'like', "%{$term}%")->orWhere('agency_name', 'like', "%{$term}%"))))
            ->when($request->query('sort') === 'priority', fn ($q) => $q->orderByRaw("case priority when 'critica' then 4 when 'alta' then 3 when 'media' then 2 else 1 end desc"))
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->paginate(min(200, (int) $request->query('per_page', 25)));

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
        ]);
        $to = TicketStatus::from($data['status']);
        Gate::authorize($to === TicketStatus::Cancelled ? 'manage' : 'work', $ticket);

        $this->tickets->transition($ticket, $to, $request->user(), $data['note'] ?? null, isset($data['at']) ? CarbonImmutable::parse($data['at']) : null);

        return $this->detail($ticket);
    }

    /** Editar prioridad, falla, detalle o caso del banco (queda en el historial). */
    public function update(Request $request, Ticket $ticket): TicketResource
    {
        Gate::authorize('manage', $ticket);
        $data = $request->validate([
            'priority' => ['sometimes', Rule::enum(Priority::class)],
            'fault_type_id' => ['sometimes', 'nullable', 'integer', 'exists:fault_types,id'],
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
        // Quien comenta queda siguiendo el ticket, como en JIRA.
        $ticket->watchers()->syncWithoutDetaching([$request->user()->id]);

        return $this->detail($ticket);
    }

    public function watch(Request $request, Ticket $ticket): TicketResource
    {
        Gate::authorize('view', $ticket);
        $request->isMethod('delete')
            ? $ticket->watchers()->detach($request->user()->id)
            : $ticket->watchers()->syncWithoutDetaching([$request->user()->id]);

        return $this->detail($ticket);
    }

    private function detail(Ticket $ticket): TicketResource
    {
        return new TicketResource($ticket->load([
            'atm.state', 'atm.bank', 'bank', 'faultType', 'technician', 'opener',
            'visits.parts', 'visits.technician', 'visits.faultType', 'visits.atmCondition',
            'events.user', 'comments.user', 'watchers',
        ]));
    }
}
