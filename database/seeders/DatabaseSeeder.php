<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(AtmConditionSeeder::class);

        // Administrador inicial; credenciales en .env (ADMIN_EMAIL / ADMIN_PASSWORD).
        if (env('ADMIN_EMAIL') && env('ADMIN_PASSWORD')) {
            User::updateOrCreate(['email' => env('ADMIN_EMAIL')], [
                'name' => env('ADMIN_NAME', 'Administrador'),
                'password' => env('ADMIN_PASSWORD'),
                'role' => Role::Admin,
                'email_verified_at' => now(),
            ]);
        }
    }
}
