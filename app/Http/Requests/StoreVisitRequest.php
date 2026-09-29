<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreVisitRequest extends FormRequest
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
            'client_uuid' => ['nullable', 'uuid'],
            'constancia_number' => ['nullable', 'string', 'max:30'],
            'fault_type_id' => ['nullable', 'integer', 'exists:fault_types,id'],
            'work_done' => ['required', 'string', 'max:5000'],
            'atm_condition_id' => ['required', 'integer', 'exists:atm_conditions,id'],
            'arrived_at' => ['nullable', 'date'],
            'finished_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
            'parts_changed' => ['required', 'boolean'],
            // RF-3.2: si hubo cambio de repuesto, es obligatorio indicar cuál y cuántos.
            'parts' => ['exclude_unless:parts_changed,true', 'required', 'array', 'min:1'],
            'parts.*.part_id' => ['required', 'integer', 'distinct', 'exists:parts,id'],
            'parts.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'photo' => ['nullable', 'image', 'max:15360'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'work_done' => 'trabajo realizado',
            'atm_condition_id' => 'estatus final',
            'parts' => 'repuestos utilizados',
            'parts.*.part_id' => 'repuesto',
            'parts.*.quantity' => 'cantidad',
            'photo' => 'foto de la constancia',
        ];
    }
}
