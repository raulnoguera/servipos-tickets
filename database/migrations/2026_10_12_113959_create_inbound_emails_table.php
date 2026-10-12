<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correos de reporte de falla leídos del buzón de la empresa. Cada correo se
 * procesa una sola vez (por su Message-ID) y queda con lo que el sistema hizo
 * con él: abrir un ticket, agregarlo a uno abierto, dejarlo por revisar o ignorarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_emails', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 191)->unique();
            $table->string('from_email', 191);
            $table->string('from_name', 191)->nullable();
            $table->string('subject', 500)->nullable();
            $table->timestamp('sent_at')->index();
            $table->text('body')->nullable();
            $table->string('status', 20)->index();               // ticket | seguimiento | revisar | ignorado
            $table->string('reason', 300)->nullable();           // por qué quedó por revisar o se ignoró
            $table->json('parsed')->nullable();                  // atm_number, agency, serial, fault, observation
            $table->foreignId('atm_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_emails');
    }
};
