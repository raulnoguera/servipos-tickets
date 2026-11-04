<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /** Contraseña en claro de los usuarios de prueba: se genera al azar en cada ejecución. */
    protected static ?string $plainPassword = null;

    public static function plainPassword(): string
    {
        return static::$plainPassword ??= Str::password(16, symbols: false);
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make(static::plainPassword()),
            'remember_token' => Str::random(10),
            'role' => Role::Operator,
            'active' => true,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => Role::Admin]);
    }

    public function technician(): static
    {
        return $this->state(fn () => ['role' => Role::Technician, 'phone' => '+58414'.fake()->numerify('#######')]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
