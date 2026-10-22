<?php

namespace App\Http\Controllers\Api;

use App\Enums\Priority;
use App\Enums\Zone;
use App\Http\Controllers\Controller;
use App\Http\Resources\TicketResource;
use App\Models\Atm;
use App\Models\InboundEmail;
use App\Models\Scopes\BankScope;
use App\Services\AuditLogger;
use App\Services\Inbound\InboundMailProcessor;
use App\Services\Inbound\Mailbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Bandeja de correos de los bancos: qué hizo el sistema con cada uno y los que esperan una decisión. */
class InboundEmailController extends Controller
{
    public function index(Request $request, Mailbox $mailbox): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(InboundEmail::STATUSES)],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $term = addcslashes(trim($filters['q'] ?? ''), '%_\\');

        $emails = InboundEmail::query()
            ->with(['atm:id,atm_number,agency_name,serial,active', 'ticket:id,number,status', 'handler:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('subject', 'like', "%{$term}%")
                ->orWhere('from_email', 'like', "%{$term}%")
                ->orWhere('from_name', 'like', "%{$term}%")
                ->orWhere('body', 'like', "%{$term}%")))
            ->orderByDesc('sent_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'data' => collect($emails->items())->map(fn (InboundEmail $email) => $this->present($email)),
            'meta' => [
                'current_page' => $emails->currentPage(), 'last_page' => $emails->lastPage(),
                'from' => $emails->firstItem(), 'to' => $emails->lastItem(), 'total' => $emails->total(), 'per_page' => $emails->perPage(),
                'counts' => InboundEmail::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
                'reading' => $mailbox->configured(),
                'last_read_at' => Cache::get(Mailbox::LAST_READ),
                'last_error' => Cache::get(Mailbox::LAST_ERROR),
            ],
        ]);
    }

    /** Abre el ticket de un correo que quedó por revisar, con el cajero que elija la persona. */
    public function ticket(Request $request, InboundEmail $email, InboundMailProcessor $processor, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'atm_id' => ['required', 'integer', Rule::exists('atms', 'id')->where('active', true)->where(fn ($q) => BankScope::banks() === null ? $q : $q->whereIn('bank_id', BankScope::banks()))],
            'fault_type_id' => ['nullable', 'integer', Rule::exists('fault_types', 'id')->where('active', true)],
            'sla_zone' => ['nullable', Rule::enum(Zone::class)],
            'priority' => ['nullable', Rule::enum(Priority::class)],
        ], ['atm_id.exists' => 'El cajero no existe, está inactivo o es de un banco que usted no atiende.'], ['atm_id' => 'cajero', 'fault_type_id' => 'tipo de falla']);

        $ticket = DB::transaction(function () use ($email, $data, $processor, $request) {
            $email = InboundEmail::whereKey($email->id)->lockForUpdate()->firstOrFail();
            $this->ensurePending($email);

            $ticket = $processor->openTicket($email, Atm::findOrFail($data['atm_id']), $request->user(), $data['fault_type_id'] ?? null, $data['sla_zone'] ?? null, $data['priority'] ?? null);
            $email->update([
                'status' => InboundEmail::TICKET, 'atm_id' => $ticket->atm_id, 'ticket_id' => $ticket->id,
                'handled_by' => $request->user()->id, 'handled_at' => now(),
            ]);

            return $ticket;
        });
        $audit->record('email.ticket', "Ticket {$ticket->number} abierto desde un correo por revisar", $ticket, ['correo' => $email->id]);

        return response()->json(['data' => new TicketResource($ticket->load(['atm.bank', 'atm.state', 'faultType']))], 201);
    }

    /** Vuelve a evaluar los correos por revisar: útil después de cargar o corregir cajeros. */
    public function reevaluate(InboundMailProcessor $processor, AuditLogger $audit): JsonResponse
    {
        $result = $processor->reevaluatePending();
        $audit->record('email.reevaluated', "Correos por revisar reevaluados: {$result['tickets']} ticket(s) abiertos, {$result['follow_ups']} agregados, {$result['pending']} siguen pendientes", meta: $result);

        return response()->json($result);
    }

    public function ignore(Request $request, InboundEmail $email, AuditLogger $audit): JsonResponse
    {
        $this->ensurePending($email);
        $email->update(['status' => InboundEmail::IGNORED, 'handled_by' => $request->user()->id, 'handled_at' => now()]);
        $audit->record('email.ignored', 'Correo por revisar descartado: '.mb_strimwidth((string) $email->subject, 0, 120, '…'), $email);

        return response()->json(['data' => $this->present($email->load(['atm', 'ticket', 'handler']))]);
    }

    private function ensurePending(InboundEmail $email): void
    {
        if ($email->status !== InboundEmail::REVIEW) {
            throw ValidationException::withMessages(['email' => 'Este correo ya fue atendido.']);
        }
    }

    /** @return array<string, mixed> */
    private function present(InboundEmail $email): array
    {
        return [
            'id' => $email->id,
            'sent_at' => $email->sent_at->toIso8601String(),
            'from_email' => $email->from_email,
            'from_name' => $email->from_name,
            'subject' => $email->subject,
            'body' => $email->body,
            'status' => $email->status,
            'reason' => $email->reason,
            'parsed' => $email->parsed,
            'atm' => $email->atm ? [
                'id' => $email->atm->id, 'atm_number' => $email->atm->atm_number, 'agency_name' => $email->atm->agency_name,
                'serial' => $email->atm->serial, 'active' => $email->atm->active, 'zone' => $email->atm->zone,
            ] : null,
            'ticket' => $email->ticket ? ['id' => $email->ticket->id, 'number' => $email->ticket->number, 'status' => $email->ticket->status] : null,
            'handled_by' => $email->handler?->name,
            'handled_at' => $email->handled_at?->toIso8601String(),
        ];
    }
}
