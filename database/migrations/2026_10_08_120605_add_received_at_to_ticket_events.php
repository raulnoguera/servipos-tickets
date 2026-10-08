<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hora en que el servidor recibió cada cambio de estado. `created_at` es la hora
     * en que ocurrió según el teléfono (puede ser anterior si se envió sin señal);
     * la diferencia entre ambas deja a la vista los registros tardíos.
     */
    public function up(): void
    {
        Schema::table('ticket_events', function (Blueprint $table) {
            $table->timestamp('received_at', 3)->nullable()->after('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_events', function (Blueprint $table) {
            $table->dropColumn('received_at');
        });
    }
};
