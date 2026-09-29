<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAtmRequest extends FormRequest
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
        return [
            'atm_number' => ['required', 'string', 'max:30', Rule::unique('atms')->ignore($this->route('atm'))],
            'serial' => ['nullable', 'string', 'max:60'],
            'bank_id' => ['required', 'integer', 'exists:banks,id'],
            'state_id' => ['nullable', 'integer', 'exists:states,id'],
            'agency_code' => ['nullable', 'string', 'max:30'],
            'agency_name' => ['required', 'string', 'max:255'],
            'site_type' => ['nullable', 'string', 'max:40'],
            'brand' => ['nullable', 'string', 'max:40'],
            'model' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:255'],
            'active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('atm_number')) {
            $this->merge(['atm_number' => strtoupper(trim((string) $this->input('atm_number')))]);
        }
    }
}
