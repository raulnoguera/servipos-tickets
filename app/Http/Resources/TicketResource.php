<?php

namespace App\Http\Resources;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\SlaService;
use App\Services\WhatsAppLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Ticket */
class TicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $end = $this->closed_at ?? now();

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'priority' => $this->priority?->value ?? 'media',
            'priority_label' => $this->priority?->label() ?? 'Media',
            'sla' => app(SlaService::class)->evaluate($this->resource),
            'responded_at' => $this->responded_at?->toIso8601String(),
            'source' => $this->source,
            'description' => $this->description,
            'bank_reference' => $this->bank_reference,
            'bank' => $this->bank?->name,
            'fault_type_id' => $this->fault_type_id,
            'fault_type' => $this->faultType?->name,
            'atm' => new AtmResource($this->whenLoaded('atm')),
            'technician' => $this->technician ? [
                'id' => $this->technician->id,
                'name' => $this->technician->name,
                // Un técnico no ve el teléfono de sus compañeros (sí el propio).
                'phone' => $request->user()?->role->value !== 'technician' || $request->user()->is($this->technician) ? $this->technician->phone : null,
            ] : null,
            'opened_by' => $this->whenLoaded('opener', fn () => $this->opener?->name),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'confirmed_by' => $this->whenLoaded('confirmer', fn () => $this->confirmer?->name),
            // Qué puede hacer quien consulta con el cierre informado por el técnico.
            'can_confirm' => $this->status === TicketStatus::PendingClosure && (bool) $request->user()?->can('confirm', $this->resource),
            // Histórico importado: sin horas, no se mide duración.
            'duration_ms' => $this->duration_ms ?? ($this->source === 'import' ? null : (int) $this->opened_at->diffInMilliseconds($end)),
            'is_running' => ! $this->status->clockStopped(),
            'visits' => VisitResource::collection($this->whenLoaded('visits')),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'at' => $e->created_at->toIso8601String(),
                // Enviado tarde (guardado sin señal): hora en que llegó al servidor y demora.
                'received_at' => $e->lateMs() ? $e->received_at->toIso8601String() : null,
                'late_ms' => $e->lateMs(),
                'user' => $e->user?->name,
                'from' => $e->from_status,
                'to' => $e->to_status,
                'note' => $e->note,
            ])),
            'comments' => $this->whenLoaded('comments', fn () => $this->comments->map(fn ($c) => [
                'id' => $c->id,
                'at' => $c->created_at->toIso8601String(),
                'user' => $c->user?->name,
                'user_id' => $c->user_id,
                'body' => $c->body,
            ])),
            'watchers' => $this->whenLoaded('watchers', fn () => $this->watchers->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])),
            'watching' => $this->whenLoaded('watchers', fn () => $this->watchers->contains('id', $request->user()?->id)),
            'whatsapp_url' => $this->when(
                $request->user()?->role->value !== 'technician' && $this->technician?->phone,
                fn () => WhatsAppLink::forTicket($this->resource),
            ),
        ];
    }
}
