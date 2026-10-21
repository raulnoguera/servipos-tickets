<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de marcas de cajeros y sus modelos. El cajero sigue guardando el
 * nombre de su marca y su modelo; el catálogo define qué valores se pueden elegir.
 * Se llena con lo que ya tienen los cajeros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atm_brands', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('atm_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('atm_brand_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['atm_brand_id', 'name']);
        });

        $now = now();
        $brands = DB::table('atms')->whereNotNull('brand')->distinct()->pluck('brand')
            ->map(fn ($b) => mb_strtoupper(trim($b)))->push('DIEBOLD')->filter()->unique();
        foreach ($brands as $brand) {
            $id = DB::table('atm_brands')->insertGetId(['name' => $brand, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('atms')->whereRaw('UPPER(TRIM(brand)) = ?', [$brand])->whereNotNull('model')->distinct()->pluck('model')
                ->map(fn ($m) => mb_strtoupper(trim($m)))->filter()->unique()
                ->each(fn ($model) => DB::table('atm_models')->insert(['atm_brand_id' => $id, 'name' => $model, 'active' => true, 'created_at' => $now, 'updated_at' => $now]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atm_models');
        Schema::dropIfExists('atm_brands');
    }
};
