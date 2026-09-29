<?php

namespace Database\Seeders;

use App\Enums\TicketStatus;
use App\Models\AtmCondition;
use Illuminate\Database\Seeder;

/** Valores de "Estatus Final" del consolidado actual y el estado de ticket al que llevan. */
class AtmConditionSeeder extends Seeder
{
    public const CONDITIONS = [
        'Operativo' => TicketStatus::Closed,
        'Operativo / Pendiente por Repuesto' => TicketStatus::PendingPart,
        'Fuera de Servicio' => TicketStatus::PendingValidation,
        'Fuera de Servicio/Pendiente por 3ero' => TicketStatus::PendingValidation,
    ];

    public function run(): void
    {
        $sort = 0;
        foreach (self::CONDITIONS as $name => $status) {
            AtmCondition::updateOrCreate(['name' => $name], [
                'resulting_ticket_status' => $status,
                'sort' => ++$sort,
            ]);
        }
    }
}
