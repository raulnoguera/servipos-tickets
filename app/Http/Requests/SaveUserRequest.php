<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SaveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            // El técnico entra con su teléfono; el correo solo es obligatorio para los demás roles.
            'email' => ['nullable', 'required_unless:role,technician', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'role' => ['required', Rule::enum(Role::class)],
            'phone' => ['nullable', 'required_if:role,technician', 'string', 'max:30', function (string $attribute, mixed $value, Closure $fail) use ($user) {
                $normalized = User::normalizePhone((string) $value);
                if ($normalized === null) {
                    $fail('Escriba un teléfono válido, por ejemplo 0414-1234567.');
                } elseif (User::where('phone_normalized', $normalized)->when($user, fn ($q) => $q->whereKeyNot($user->id))->exists()) {
                    $fail('Ese teléfono ya lo usa otro usuario.');
                }
            }],
            'active' => ['boolean'],
            // Opcional: sin contraseña, el usuario crea la suya con el enlace de acceso.
            'password' => ['nullable', Password::min(10)->letters()->numbers()],
            'state_ids' => ['array'],
            'state_ids.*' => ['integer', 'exists:states,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required_if' => 'El teléfono es obligatorio para los técnicos: es su usuario para ingresar.',
            'email.required_unless' => 'El correo es obligatorio para administradores y operadores.',
        ];
    }
}
