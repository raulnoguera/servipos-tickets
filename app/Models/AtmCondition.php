<?php

namespace App\Models;

use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** "Estatus Final" del Excel: cómo queda el cajero al terminar una visita. */
#[Fillable(['name', 'resulting_ticket_status', 'sort', 'active'])]
class AtmCondition extends Model
{
    protected function casts(): array
    {
        return [
            'resulting_ticket_status' => TicketStatus::class,
            'active' => 'boolean',
        ];
    }
}
