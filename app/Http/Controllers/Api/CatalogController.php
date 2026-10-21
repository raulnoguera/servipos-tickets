<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Enums\Zone;
use App\Http\Controllers\Controller;
use App\Models\Atm;
use App\Models\AtmBrand;
use App\Models\AtmCondition;
use App\Models\AtmModel;
use App\Models\Bank;
use App\Models\FaultType;
use App\Models\Part;
use App\Models\State;
use App\Models\User;
use App\Services\AuditLogger;
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
        'brands' => AtmBrand::class,
        'models' => AtmModel::class,
    ];

    private const LABELS = [
        'banks' => 'Bancos', 'states' => 'Estados', 'fault-types' => 'Tipos de falla',
        'parts' => 'Repuestos', 'atm-conditions' => 'Estatus final', 'brands' => 'Marcas', 'models' => 'Modelos',
    ];

    /** Todo lo que necesitan los formularios, en una sola llamada (también sirve de caché offline). */
    public function all(Request $request): JsonResponse
    {
        // Los teléfonos de los técnicos son para quien asigna; un técnico no ve los de sus compañeros.
        $staff = $request->user()->role !== Role::Technician;

        $list = fn (string $model) => $model::query()->orderBy('name')->get(['id', 'name', ...($model === State::class ? [] : ['active'])]);

        return response()->json([
            // El operador asignado a ciertos bancos solo los ve a ellos en los filtros.
            'banks' => $list(Bank::class)->when($request->user()->bankScope() !== null, fn ($banks) => $banks->whereIn('id', $request->user()->bankScope())->values()),
            'states' => $list(State::class),
            'fault_types' => $list(FaultType::class),
            'parts' => $list(Part::class),
            'atm_conditions' => AtmCondition::orderBy('sort')->get(['id', 'name', 'resulting_ticket_status', 'active']),
            'brands' => AtmBrand::with('models:id,atm_brand_id,name,active')->orderBy('name')->get(['id', 'name', 'active']),
            'technicians' => User::technicians()->where('active', true)->with('states:id')->orderBy('name')->get()
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'phone' => $staff ? $u->phone : null, 'state_ids' => $u->states->pluck('id')]),
            'statuses' => collect(TicketStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'zones' => collect(Zone::cases())->map(fn ($z) => ['value' => $z->value, 'label' => $z->label()]),
            'roles' => collect(Role::cases())->map(fn ($r) => ['value' => $r->value, 'label' => $r->label()]),
        ]);
    }

    public function store(Request $request, string $type, AuditLogger $audit): JsonResponse
    {
        $model = $this->model($type);
        $record = $model::create($this->validated($request, $type));
        $audit->record('catalog.created', self::LABELS[$type].": se agregó \"{$record->name}\"", $record);

        return response()->json($record, 201);
    }

    public function update(Request $request, string $type, int $id, AuditLogger $audit): JsonResponse
    {
        $record = $this->model($type)::findOrFail($id);
        $before = $record->name;
        $record->update($this->validated($request, $type, $id, $record));
        $this->renameOnAtms($record, $before);
        if ($record->wasChanged()) {
            $audit->record('catalog.updated', self::LABELS[$type].": se modificó \"{$record->name}\"", $record, ['cambios' => array_keys($record->getChanges())]);
        }

        return response()->json($record);
    }

    /**
     * El cajero guarda el nombre de su marca y su modelo: al renombrarlos en el
     * catálogo, se renombran también en los cajeros que los usan.
     */
    private function renameOnAtms(Model $record, string $before): void
    {
        if (! $record->wasChanged('name')) {
            return;
        }
        if ($record instanceof AtmBrand) {
            Atm::withoutGlobalScopes()->where('brand', $before)->update(['brand' => $record->name]);
        } elseif ($record instanceof AtmModel) {
            Atm::withoutGlobalScopes()->where('brand', $record->brand->name)->where('model', $before)->update(['model' => $record->name]);
        }
    }

    /** @return class-string<Model> */
    private function model(string $type): string
    {
        return self::TYPES[$type] ?? abort(404);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, string $type, ?int $id = null, ?Model $record = null): array
    {
        $table = (new (self::TYPES[$type]))->getTable();
        $rules = ['name' => ['required', 'string', 'max:255', Rule::unique($table)->ignore($id)]];
        if (in_array($type, ['parts', 'brands', 'models'], true)) {
            $request->merge(['name' => mb_strtoupper(trim((string) $request->input('name')))]);
        }
        if ($type === 'brands') {
            $rules['name'][2] = 'max:40';
        }
        if ($type === 'models') {
            // El modelo pertenece a una marca: se indica al crearlo y no cambia después.
            $brandId = $record?->atm_brand_id ?? (int) $request->input('atm_brand_id');
            $rules['name'] = ['required', 'string', 'max:60', Rule::unique('atm_models')->where('atm_brand_id', $brandId)->ignore($id)];
            if (! $record) {
                $rules['atm_brand_id'] = ['required', 'integer', 'exists:atm_brands,id'];
            }
        }
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

        return $request->validate($rules);
    }
}
