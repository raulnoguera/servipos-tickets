<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tickets (RF-2.x) y visitas (RF-3.x). Un ticket agrupa una o más visitas;
 * cada visita equivale a una fila de la "Base General" del Excel.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Contador correlativo por año, bloqueado con SELECT ... FOR UPDATE.
        Schema::create('ticket_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();              // TK-2026-00001
            $table->foreignId('atm_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fault_type_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description')->nullable();             // detalle del reporte del banco
            $table->string('bank_reference', 60)->nullable();    // N° de caso del banco, si lo trae el correo
            $table->string('status', 30)->index();
            $table->string('source', 20)->default('manual');     // manual | email | import
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            // Tiempos del ciclo (RF-3.4). Precisión de milisegundos; el reloj no se
            // detiene mientras el ticket espera repuesto.
            $table->timestamp('opened_at', 3)->index();
            $table->timestamp('assigned_at', 3)->nullable();
            $table->timestamp('closed_at', 3)->nullable()->index();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['atm_id', 'status']);
        });

        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->nullable()->unique();   // idempotencia de la sincronización offline
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('fault_type_id')->nullable()->constrained()->nullOnDelete(); // "Reporte de Falla"
            $table->string('constancia_number', 30)->nullable()->index();
            $table->date('visit_date')->index();
            $table->timestamp('arrived_at', 3)->nullable();
            $table->timestamp('finished_at', 3)->nullable();
            $table->text('work_done')->nullable();                // "Trabajo realizado/Observación"
            $table->boolean('parts_changed')->default(false);     // "Cambio de Repuesto" Sí/No
            $table->foreignId('atm_condition_id')->nullable()->constrained()->nullOnDelete(); // "Estatus Final"
            $table->string('constancia_path')->nullable();        // foto comprimida de la planilla
            $table->timestamps();
        });

        Schema::create('visit_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->unique(['visit_id', 'part_id']);
        });

        // Bitácora de cambios de estado (trazabilidad).
        Schema::create('ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->string('note')->nullable();
            $table->timestamp('created_at', 3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_events');
        Schema::dropIfExists('visit_parts');
        Schema::dropIfExists('visits');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_sequences');
    }
};
