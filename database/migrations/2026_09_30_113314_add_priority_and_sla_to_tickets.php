<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prioridad del ticket, hora de llegada al sitio (primera respuesta) y
 * políticas de SLA por prioridad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('priority', 10)->default('media')->after('status')->index();
            // Primera llegada al sitio: "Llegué al sitio" o, si no se marcó, la primera atención.
            $table->timestamp('responded_at', 3)->nullable()->after('assigned_at');
        });

        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->string('priority', 10)->unique();
            $table->unsignedInteger('response_minutes')->nullable();   // apertura → llegada al sitio
            $table->unsignedInteger('resolution_minutes')->nullable(); // apertura → cierre
            $table->timestamps();
        });

        // Valores de ejemplo (editables por el administrador).
        $now = now();
        DB::table('sla_policies')->insert([
            ['priority' => 'critica', 'response_minutes' => 120, 'resolution_minutes' => 240, 'created_at' => $now, 'updated_at' => $now],
            ['priority' => 'alta', 'response_minutes' => 240, 'resolution_minutes' => 480, 'created_at' => $now, 'updated_at' => $now],
            ['priority' => 'media', 'response_minutes' => 480, 'resolution_minutes' => 1440, 'created_at' => $now, 'updated_at' => $now],
            ['priority' => 'baja', 'response_minutes' => 1440, 'resolution_minutes' => 2880, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Tickets existentes: llegada = primer "en sitio" o primera atención registrada.
        $onSite = DB::table('ticket_events')->where('to_status', 'en_sitio')
            ->select('ticket_id', DB::raw('min(created_at) as at'))->groupBy('ticket_id')->pluck('at', 'ticket_id');
        $visits = DB::table('visits')->whereNotNull('finished_at')
            ->select('ticket_id', DB::raw('min(coalesce(arrived_at, finished_at)) as at'))->groupBy('ticket_id')->pluck('at', 'ticket_id');
        DB::table('tickets')->where('source', '!=', 'import')->orderBy('id')->each(function ($ticket) use ($onSite, $visits) {
            $at = collect([$onSite[$ticket->id] ?? null, $visits[$ticket->id] ?? null])->filter()->min();
            if ($at) {
                DB::table('tickets')->where('id', $ticket->id)->update(['responded_at' => $at]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_policies');
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['priority']);
            $table->dropColumn(['priority', 'responded_at']);
        });
    }
};
