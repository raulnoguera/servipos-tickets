<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bancos que atiende cada operador del Call Center. Un operador sin bancos
 * asignados atiende todos; con bancos asignados, solo ve lo de esos bancos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_user', function (Blueprint $table) {
            $table->foreignId('bank_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['bank_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_user');
    }
};
