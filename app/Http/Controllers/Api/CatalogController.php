<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\AtmCondition;
use App\Models\Bank;
use App\Models\FaultType;
use App\Models\Part;
use App\Models\State;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Catálogos pequeños (RF-1.2, RF-1.4): bancos, estados, tipos de falla,
 * repuestos y estatus final. Un solo controlador con la lista de tipos.
 */
class CatalogController extends Controller
{
    /** @var array<string, class-string<Model>> */
    private const TYPES = [
        'banks' => Bank::class,
        'states' => State::class,
        'fault-types' => FaultType::class,
        'parts' => Part::class,
        'atm-conditions' => AtmCondition::class,
    ];

    /** Todo lo que necesitan los formularios, en una sola llamada (también sirve de caché offline). */
    public function all(): JsonResponse
    {
        $list = fn (string $model) => $model::query()->orderBy('name')->get(['id', 'name', ...($model === State::class ? [] : ['active'])]);

        return response()->json([
            'banks' => $list(Bank::class),
            'states' => $list(State::class),
            'fault_types' => $list(FaultType::class),
            'parts' => $list(Part::class),
            'atm_conditions' => AtmCondition::orderBy('sort')->get(['id', 'name', 'resulting_ticket_status', 'active']),
            'technicians' => User::technicians()->where('active', true)->with('states:id')->orderBy('name')->get()
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'phone' => $u->phone, 'state_ids' => $u->states->pluck('id')]),
            'statuses' => collect(TicketStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'roles' => collect(Role::cases())->map(fn ($r) => ['value' => $r->value, 'label' => $r->label()]),
        ]);
    }

    public function store(Request $request, string $type): JsonResponse
    {
        $model = $this->model($type);

        return response()->json($model::create($this->validated($request, $type)), 201);
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        $record = $this->model($type)::findOrFail($id);
        $record->update($this->validated($request, $type, $id));

        return response()->json($record);
    }

    /** @return class-string<Model> */
    private function model(string $type): string
    {
        return self::TYPES[$type] ?? abort(404);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, string $type, ?int $id = null): array
    {
        $table = (new (self::TYPES[$type]))->getTable();
        $rules = ['name' => ['required', 'string', 'max:255', Rule::unique($table)->ignore($id)]];
        if ($type !== 'states') {
            $rules['active'] = ['boolean'];
        }
        if ($type === 'banks') {
            $rules['short_name'] = ['nullable', 'string', 'max:30'];
        }
        if ($type === 'atm-conditions') {
            $rules['resulting_ticket_status'] = ['required', Rule::in([TicketStatus::Closed->value, TicketStatus::PendingPart->value, TicketStatus::PendingValidation->value])];
            $rules['sort'] = ['integer', 'min:0'];
        }
        if ($type === 'parts') {
            $request->merge(['name' => mb_strtoupper(trim((string) $request->input('name')))]);
        }

        return $request->validate($rules);
    }
}
