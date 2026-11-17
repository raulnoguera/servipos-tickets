<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El cliente deja de usar «Pendiente por validación» (17/11/2026): trabaja solo
 * con «Pendiente por repuesto» y «Por confirmar». Los estatus finales «Fuera de
 * Servicio» pasan a dejar el ticket pendiente por repuesto, con el reloj corriendo,
 * y los tickets que estaban en validación se mueven a ese estado dejando el rastro.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('atm_conditions')->where('resulting_ticket_status', 'pendiente_validacion')
            ->update(['resulting_ticket_status' => 'pendiente_repuesto', 'updated_at' => now()]);

        $now = now();
        foreach (DB::table('tickets')->where('status', 'pendiente_validacion')->pluck('id') as $id) {
            DB::table('tickets')->where('id', $id)->update(['status' => 'pendiente_repuesto', 'updated_at' => $now]);
            DB::table('ticket_events')->insert([
                'ticket_id' => $id,
                'from_status' => 'pendiente_validacion',
                'to_status' => 'pendiente_repuesto',
                'note' => 'Se eliminó el estado «Pend. validación»: el ticket pasa a «Pend. repuesto».',
                'created_at' => $now,
                'received_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Sin vuelta atrás automática: no se distingue qué ticket venía de validación
        // salvo por su evento, y el cambio fue pedido por el cliente.
    }
};
