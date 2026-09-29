<?php

namespace App\Http\Requests;

use App\Enums\Role;
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
        $creating = $this->route('user') === null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($this->route('user'))],
            'role' => ['required', Rule::enum(Role::class)],
            'phone' => ['nullable', 'string', 'max:30'],
            'active' => ['boolean'],
            'password' => [$creating ? 'required' : 'nullable', Password::min(8)],
            'state_ids' => ['array'],
            'state_ids.*' => ['integer', 'exists:states,id'],
        ];
    }
}
