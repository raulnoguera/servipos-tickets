<?php

namespace App\Http\Resources;

use App\Models\Ticket;
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
            'source' => $this->source,
            'description' => $this->description,
            'bank_reference' => $this->bank_reference,
            'bank' => $this->bank?->name,
            'fault_type_id' => $this->fault_type_id,
            'fault_type' => $this->faultType?->name,
            'atm' => new AtmResource($this->whenLoaded('atm')),
            'technician' => $this->technician ? ['id' => $this->technician->id, 'name' => $this->technician->name, 'phone' => $this->technician->phone] : null,
            'opened_by' => $this->whenLoaded('opener', fn () => $this->opener?->name),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            // Histórico importado: sin horas, no se mide duración.
            'duration_ms' => $this->duration_ms ?? ($this->source === 'import' ? null : (int) $this->opened_at->diffInMilliseconds($end)),
            'is_running' => ! $this->status->isFinal(),
            'visits' => VisitResource::collection($this->whenLoaded('visits')),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'at' => $e->created_at->toIso8601String(),
                'user' => $e->user?->name,
                'from' => $e->from_status,
                'to' => $e->to_status,
                'note' => $e->note,
            ])),
            'whatsapp_url' => $this->when(
                $request->user()?->role->value !== 'technician' && $this->technician?->phone,
                fn () => WhatsAppLink::forTicket($this->resource),
            ),
        ];
    }
}
