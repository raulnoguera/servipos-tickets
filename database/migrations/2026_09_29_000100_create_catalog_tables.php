<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maestros (RF-1.x): bancos, estados, cajeros, tipos de falla, repuestos y
 * condición final del cajero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('short_name', 30)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('states', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        // Estados/regiones que cubre cada técnico (RF-1.3).
        Schema::create('state_user', function (Blueprint $table) {
            $table->foreignId('state_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['state_id', 'user_id']);
        });

        Schema::create('atms', function (Blueprint $table) {
            $table->id();
            $table->string('atm_number', 30)->unique();          // N° ATM (ej. A1918035)
            $table->string('serial', 60)->nullable()->index();
            $table->foreignId('bank_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('state_id')->nullable()->constrained()->nullOnDelete();
            $table->string('agency_code', 30)->nullable()->index();
            $table->string('agency_name')->nullable();
            $table->string('site_type', 40)->nullable();         // AGENCIA | DIRECCIÓN GENERAL
            $table->string('brand', 40)->nullable();
            $table->string('model', 60)->nullable();
            $table->string('address')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('fault_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('parts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // "Estatus Final" del Excel: cómo queda el cajero al terminar una visita,
        // y a qué estado lleva el ticket.
        Schema::create('atm_conditions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('resulting_ticket_status', 30);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atm_conditions');
        Schema::dropIfExists('parts');
        Schema::dropIfExists('fault_types');
        Schema::dropIfExists('atms');
        Schema::dropIfExists('state_user');
        Schema::dropIfExists('states');
        Schema::dropIfExists('banks');
    }
};
