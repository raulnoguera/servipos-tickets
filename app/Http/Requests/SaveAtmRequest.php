<?php

namespace App\Http\Requests;

use App\Models\AtmModel;
use Closure;
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
            'bank_id' => ['nullable', 'integer', 'exists:banks,id'],
            'state_id' => ['nullable', 'integer', 'exists:states,id'],
            'agency_code' => ['nullable', 'string', 'max:30'],
            'agency_name' => ['required', 'string', 'max:255'],
            'site_type' => ['nullable', 'string', 'max:40'],
            // Marca y modelo salen del catálogo (Catálogos → Marcas y modelos).
            'brand' => ['nullable', 'string', 'max:40', Rule::exists('atm_brands', 'name')],
            'model' => ['nullable', 'string', 'max:60', function (string $attribute, mixed $value, Closure $fail) {
                $brand = $this->input('brand');
                if (! $brand || ! AtmModel::where('name', $value)->whereHas('brand', fn ($b) => $b->where('name', $brand))->exists()) {
                    $fail('Elija un modelo de la marca seleccionada.');
                }
            }],
            'address' => ['nullable', 'string', 'max:255'],
            'active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('atm_number')) {
            $this->merge(['atm_number' => strtoupper(trim((string) $this->input('atm_number')))]);
        }
        foreach (['brand', 'model'] as $field) {
            if ($this->filled($field)) {
                $this->merge([$field => mb_strtoupper(trim((string) $this->input($field)))]);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['brand.exists' => 'Elija una marca del catálogo (Catálogos → Marcas y modelos).'];
    }
}
