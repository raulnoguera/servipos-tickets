<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Genera el N° correlativo de ticket (RF-2.2): TK-2026-00001.
 * La numeración vuelve a 1 cada año. Debe llamarse dentro de una transacción
 * para que el bloqueo de la fila evite números duplicados entre operadores.
 */
class TicketNumberGenerator
{
    public function next(int $year): string
    {
        return DB::transaction(function () use ($year) {
            DB::table('ticket_sequences')->insertOrIgnore(['year' => $year, 'last_number' => 0]);

            $last = DB::table('ticket_sequences')->where('year', $year)->lockForUpdate()->value('last_number');
            $next = $last + 1;
            DB::table('ticket_sequences')->where('year', $year)->update(['last_number' => $next]);

            return sprintf('TK-%d-%05d', $year, $next);
        });
    }
}
