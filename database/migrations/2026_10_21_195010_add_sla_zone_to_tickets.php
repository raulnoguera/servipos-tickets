<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tipo de SLA elegido para un ticket (urbana o extraurbana). Vacío: sigue la
 * zona del cajero; con valor: ese ticket se mide con esa zona aunque el cajero
 * tenga otra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('sla_zone', 12)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('sla_zone');
        });
    }
};
