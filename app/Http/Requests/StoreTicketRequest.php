<?php

namespace App\Http\Requests;

use App\Enums\Priority;
use App\Enums\Role;
use App\Enums\Zone;
use App\Models\Scopes\BankScope;
use App\Models\Ticket;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Ticket::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'atm_id' => ['required', 'integer', Rule::exists('atms', 'id')->where('active', true)->where(fn ($q) => BankScope::banks() === null ? $q : $q->whereIn('bank_id', BankScope::banks()))],
            'fault_type_id' => ['required', 'integer', 'exists:fault_types,id'],
            'priority' => ['nullable', Rule::enum(Priority::class)],
            'sla_zone' => ['nullable', Rule::enum(Zone::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'bank_reference' => ['nullable', 'string', 'max:60'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', Role::Technician->value)->where('active', true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['atm_id.exists' => 'El cajero no existe, está inactivo o es de un banco que usted no atiende.'];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['atm_id' => 'cajero', 'fault_type_id' => 'tipo de falla', 'assigned_to' => 'técnico', 'sla_zone' => 'tipo de SLA', 'priority' => 'prioridad'];
    }
}
