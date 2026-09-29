<?php

namespace Database\Factories;

use App\Models\Atm;
use App\Models\Bank;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Atm>
 */
class AtmFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'atm_number' => 'A'.fake()->unique()->numerify('19#####'),
            'serial' => fake()->bothify('1562RDC#####'),
            'bank_id' => Bank::firstOrCreate(['name' => 'Banco de Prueba'])->id,
            'agency_code' => (string) fake()->numberBetween(1, 300),
            'agency_name' => 'AGENCIA '.fake()->city(),
            'site_type' => 'AGENCIA',
            'brand' => 'DIEBOLD',
            'model' => 'OPTEVA 562',
        ];
    }
}
