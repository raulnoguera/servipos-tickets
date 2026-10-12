<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Si al recibir el correo se comprobó que venía de un banco registrado. Solo
 * esos se pueden volver a evaluar sin que una persona los revise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_emails', function (Blueprint $table) {
            $table->boolean('trusted')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('inbound_emails', function (Blueprint $table) {
            $table->dropColumn('trusted');
        });
    }
};
