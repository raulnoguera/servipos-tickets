<?php

use App\Services\SlaService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El SLA deja de depender de la prioridad y pasa a depender de la zona del cajero
 * (urbana o extraurbana), contando solo el horario bancario:
 * - urbana: verde hasta 4 h hábiles, naranja hasta 8 h, crítica después;
 * - extraurbana: verde hasta 1 día hábil, naranja hasta 2 días, crítica después.
 * Horario: lunes a viernes de 8:00 a 15:00, sin feriados.
 *
 * La columna «priority» de los tickets se conserva solo como historial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atms', function (Blueprint $table) {
            $table->string('zone', 12)->default('urbana')->after('site_type');
        });

        Schema::table('tickets', function (Blueprint $table) {
            // Momentos en que el ticket pasa a naranja y a crítica (en horario bancario).
            $table->timestamp('sla_orange_at', 3)->nullable()->after('duration_ms')->index();
            $table->timestamp('sla_critical_at', 3)->nullable()->after('sla_orange_at')->index();
        });

        Schema::dropIfExists('sla_policies');
        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->string('zone', 12)->unique();
            $table->string('unit', 5);                    // hours | days (días hábiles)
            $table->decimal('green', 6, 2);               // verde hasta…
            $table->decimal('orange', 6, 2);              // naranja hasta…; después, crítica
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->json('value');
            $table->timestamps();
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name', 80);
            $table->timestamps();
        });

        $now = now();
        DB::table('sla_policies')->insert([
            ['zone' => 'urbana', 'unit' => 'hours', 'green' => 4, 'orange' => 8, 'created_at' => $now, 'updated_at' => $now],
            ['zone' => 'extraurbana', 'unit' => 'days', 'green' => 1, 'orange' => 2, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('settings')->insert([
            'key' => 'sla.schedule',
            'value' => json_encode(['start' => '08:00', 'end' => '15:00', 'days' => [1, 2, 3, 4, 5]]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Feriados nacionales que quedan de 2026 y los de 2027 (el administrador los ajusta).
        $holidays = [
            '2026-12-24' => 'Víspera de Navidad', '2026-12-25' => 'Navidad', '2026-12-31' => 'Fin de año',
            '2027-01-01' => 'Año Nuevo', '2027-02-08' => 'Lunes de Carnaval', '2027-02-09' => 'Martes de Carnaval',
            '2027-03-25' => 'Jueves Santo', '2027-03-26' => 'Viernes Santo', '2027-04-19' => 'Declaración de la Independencia',
            '2027-05-01' => 'Día del Trabajador', '2027-06-24' => 'Batalla de Carabobo', '2027-07-05' => 'Día de la Independencia',
            '2027-07-24' => 'Natalicio de Simón Bolívar', '2027-10-12' => 'Día de la Resistencia Indígena',
            '2027-12-24' => 'Víspera de Navidad', '2027-12-25' => 'Navidad', '2027-12-31' => 'Fin de año',
        ];
        DB::table('holidays')->insert(collect($holidays)->map(fn ($name, $date) => ['date' => $date, 'name' => $name, 'created_at' => $now, 'updated_at' => $now])->values()->all());

        // Tickets existentes: calcular cuándo pasan a naranja y a crítica.
        app(SlaService::class)->restamp(onlyRunning: false);
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('sla_policies');
        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->string('priority', 10)->unique();
            $table->unsignedInteger('response_minutes')->nullable();
            $table->unsignedInteger('resolution_minutes')->nullable();
            $table->timestamps();
        });
        $now = now();
        DB::table('sla_policies')->insert([
            ['priority' => 'critica', 'response_minutes' => 120, 'resolution_minutes' => 240, 'created_at' => $now, 'updated_at' => $now],
            ['priority' => 'media', 'response_minutes' => 480, 'resolution_minutes' => 1440, 'created_at' => $now, 'updated_at' => $now],
            ['priority' => 'baja', 'response_minutes' => 1440, 'resolution_minutes' => 2880, 'created_at' => $now, 'updated_at' => $now],
        ]);
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['sla_orange_at']);
            $table->dropIndex(['sla_critical_at']);
            $table->dropColumn(['sla_orange_at', 'sla_critical_at']);
        });
        Schema::table('atms', function (Blueprint $table) {
            $table->dropColumn('zone');
        });
    }
};
