<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las prioridades quedan en tres: Baja, Media y Crítica. Los tickets que
 * estaban en «Alta» pasan a «Crítica», para no relajar su meta de tiempo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tickets')->where('priority', 'alta')->update(['priority' => 'critica']);
        DB::table('sla_policies')->where('priority', 'alta')->delete();
    }

    public function down(): void
    {
        if (! DB::table('sla_policies')->where('priority', 'alta')->exists()) {
            DB::table('sla_policies')->insert(['priority' => 'alta', 'response_minutes' => 240, 'resolution_minutes' => 480, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
};
