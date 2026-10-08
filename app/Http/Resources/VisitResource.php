<?php

namespace App\Http\Resources;

use App\Models\Visit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Visit */
class VisitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'constancia_number' => $this->constancia_number,
            'visit_date' => $this->visit_date?->toDateString(),
            'arrived_at' => $this->arrived_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            // Atención enviada tarde (guardada sin señal): demora entre el cierre declarado y su llegada al servidor.
            'late_ms' => $this->lateMs(),
            'technician' => $this->technician?->name,
            'fault_type' => $this->faultType?->name,
            'work_done' => $this->work_done,
            'parts_changed' => $this->parts_changed,
            'atm_condition' => $this->atmCondition?->name,
            'parts' => $this->parts->map(fn ($part) => ['id' => $part->id, 'name' => $part->name, 'quantity' => $part->pivot->quantity]),
            'constancia_url' => $this->constancia_path ? route('visits.constancia', $this->resource) : null,
        ];
    }
}
