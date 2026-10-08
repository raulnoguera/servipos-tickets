<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

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
        // Solo se crea si no existe: volver a ejecutar el seeder nunca le cambia la
        // contraseña ni el rol a un administrador que ya está en uso.
        $email = (string) env('ADMIN_EMAIL');
        $password = (string) env('ADMIN_PASSWORD');
        if ($email === '' || $password === '') {
            return;
        }
        if (str_contains($email.$password, 'CAMBIAR')) {
            throw new RuntimeException('ADMIN_EMAIL y ADMIN_PASSWORD todavía tienen el valor de ejemplo. Cámbielos en .env antes de crear el administrador.');
        }
        if (Validator::make(['password' => $password], ['password' => Password::min(10)->letters()->numbers()])->fails()) {
            throw new RuntimeException('ADMIN_PASSWORD debe tener al menos 10 caracteres, con letras y números.');
        }
        User::firstOrCreate(['email' => $email], [
            'name' => env('ADMIN_NAME', 'Administrador'),
            'password' => $password,
            'role' => Role::Admin,
            'email_verified_at' => now(),
        ]);
    }
}
