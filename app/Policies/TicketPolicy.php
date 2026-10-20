<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    /**
     * Administradores y operadores ven todo, salvo el operador asignado a ciertos
     * bancos, que ve solo los de esos bancos. El técnico ve los tickets que
     * tiene asignados y los que atendió (su historial), aunque luego se
     * hayan reasignado a otro técnico.
     */
    public function view(User $user, Ticket $ticket): bool
    {
        return ($user->hasRole(Role::Admin, Role::Operator) && $user->canSeeBank($ticket->bank_id))
            || $ticket->assigned_to === $user->id
            || $ticket->visits()->where('technician_id', $user->id)->exists();
    }

    /** Abrir, asignar y anular. */
    public function manage(User $user, ?Ticket $ticket = null): bool
    {
        return $user->hasRole(Role::Admin, Role::Operator) && ($ticket === null || $user->canSeeBank($ticket->bank_id));
    }

    /** Confirmar el cierre informado por el técnico, o devolvérselo: solo el administrador. */
    public function confirm(User $user, ?Ticket $ticket = null): bool
    {
        return $user->hasRole(Role::Admin);
    }

    /** Marcar en tránsito / en sitio y registrar la atención: solo quien lo tiene asignado. */
    public function work(User $user, Ticket $ticket): bool
    {
        return ($user->hasRole(Role::Admin, Role::Operator) && $user->canSeeBank($ticket->bank_id)) || $ticket->assigned_to === $user->id;
    }
}
