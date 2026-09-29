<?php

namespace App\Http\Resources;

use App\Models\Atm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Atm */
class AtmResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'atm_number' => $this->atm_number,
            'serial' => $this->serial,
            'bank_id' => $this->bank_id,
            'bank' => $this->whenLoaded('bank', fn () => $this->bank?->name),
            'state_id' => $this->state_id,
            'state' => $this->whenLoaded('state', fn () => $this->state?->name),
            'agency_code' => $this->agency_code,
            'agency_name' => $this->agency_name,
            'site_type' => $this->site_type,
            'brand' => $this->brand,
            'model' => $this->model,
            'address' => $this->address,
            'active' => $this->active,
            'open_tickets' => $this->whenCounted('openTickets'),
        ];
    }
}
