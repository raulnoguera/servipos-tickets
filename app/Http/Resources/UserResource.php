<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // Con qué inicia sesión (teléfono para técnicos, correo para el resto).
            'login' => $this->loginIdentifier(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'access_link_expires_at' => $this->access_token_expires_at?->isFuture() ? $this->access_token_expires_at->toIso8601String() : null,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'phone' => $this->phone,
            'active' => $this->active,
            'notify_scope' => $this->notify_scope,
            'state_ids' => $this->whenLoaded('states', fn () => $this->states->pluck('id')),
            'states' => $this->whenLoaded('states', fn () => $this->states->pluck('name')),
            'open_tickets' => $this->whenCounted('assignedOpenTickets'),
        ];
    }
}
