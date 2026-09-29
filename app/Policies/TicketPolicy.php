<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    /** Operadores y administradores ven todo; el técnico solo sus tickets. */
    public function view(User $user, Ticket $ticket): bool
    {
        return $user->hasRole(Role::Admin, Role::Operator) || $ticket->assigned_to === $user->id;
    }

    /** Abrir, asignar y anular. */
    public function manage(User $user, ?Ticket $ticket = null): bool
    {
        return $user->hasRole(Role::Admin, Role::Operator);
    }

    /** Marcar en tránsito / en sitio y registrar la atención. */
    public function work(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket);
    }
}
