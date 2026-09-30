<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    /**
     * Operadores y administradores ven todo. El técnico ve los tickets que
     * tiene asignados y los que atendió (su historial), aunque luego se
     * hayan reasignado a otro técnico.
     */
    public function view(User $user, Ticket $ticket): bool
    {
        return $user->hasRole(Role::Admin, Role::Operator)
            || $ticket->assigned_to === $user->id
            || $ticket->visits()->where('technician_id', $user->id)->exists();
    }

    /** Abrir, asignar y anular. */
    public function manage(User $user, ?Ticket $ticket = null): bool
    {
        return $user->hasRole(Role::Admin, Role::Operator);
    }

    /** Marcar en tránsito / en sitio y registrar la atención: solo quien lo tiene asignado. */
    public function work(User $user, Ticket $ticket): bool
    {
        return $user->hasRole(Role::Admin, Role::Operator) || $ticket->assigned_to === $user->id;
    }
}
