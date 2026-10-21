<?php

namespace App\Http\Controllers\Api;

use App\Enums\Zone;
use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Models\Setting;
use App\Models\SlaPolicy;
use App\Services\AuditLogger;
use App\Services\BusinessCalendar;
use App\Services\SlaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Metas de SLA por zona, horario bancario y feriados. Cualquier cambio se
 * aplica a los tickets en curso; los cerrados conservan su resultado.
 */
class SlaPolicyController extends Controller
{
    public function index(SlaService $sla): JsonResponse
    {
        return response()->json(['data' => $this->payload($sla)]);
    }

    public function update(Request $request, SlaService $sla, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'policies' => ['required', 'array'],
            'policies.*.zone' => ['required', Rule::enum(Zone::class)],
            'policies.*.unit' => ['required', Rule::in([SlaPolicy::HOURS, SlaPolicy::DAYS])],
            'policies.*.green' => ['required', 'numeric', 'min:0.25', 'max:999'],
            'policies.*.orange' => ['required', 'numeric', 'gt:policies.*.green', 'max:999'],
            'schedule' => ['sometimes', 'array'],
            'schedule.start' => ['required_with:schedule', 'date_format:H:i'],
            'schedule.end' => ['required_with:schedule', 'date_format:H:i', 'after:schedule.start'],
            'schedule.days' => ['required_with:schedule', 'array', 'min:1'],
            'schedule.days.*' => ['integer', 'between:1,7'],
        ], [
            'policies.*.orange.gt' => 'El límite de naranja debe ser mayor que el de verde.',
            'schedule.end.after' => 'La hora de cierre debe ser posterior a la de apertura.',
        ]);

        foreach ($data['policies'] as $policy) {
            SlaPolicy::updateOrCreate(['zone' => $policy['zone']], [
                'unit' => $policy['unit'], 'green' => $policy['green'], 'orange' => $policy['orange'],
            ]);
        }
        if (isset($data['schedule'])) {
            $days = array_values(array_unique(array_map('intval', $data['schedule']['days'])));
            sort($days);
            Setting::put('sla.schedule', ['start' => $data['schedule']['start'], 'end' => $data['schedule']['end'], 'days' => $days]);
        }
        $sla->restamp();
        $audit->record('sla.updated', 'Metas de SLA modificadas', meta: ['metas' => $data['policies'], 'horario' => $data['schedule'] ?? null]);

        return response()->json(['data' => $this->payload($sla)]);
    }

    public function addHoliday(Request $request, SlaService $sla, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', Rule::unique('holidays', 'date')],
            'name' => ['required', 'string', 'max:80'],
        ], ['date.unique' => 'Esa fecha ya está en la lista de feriados.'], ['date' => 'fecha', 'name' => 'motivo']);

        Holiday::create(['date' => $data['date'], 'name' => trim($data['name'])]);
        $sla->restamp();
        $audit->record('sla.updated', "Feriado agregado: {$data['date']} {$data['name']}");

        return response()->json(['data' => $this->payload($sla)], 201);
    }

    public function removeHoliday(Holiday $holiday, SlaService $sla, AuditLogger $audit): JsonResponse
    {
        $holiday->delete();
        $sla->restamp();
        $audit->record('sla.updated', "Feriado eliminado: {$holiday->date->toDateString()} {$holiday->name}");

        return response()->json(['data' => $this->payload($sla)]);
    }

    /** @return array<string, mixed> */
    private function payload(SlaService $sla): array
    {
        $sla->forget();
        $policies = $sla->policies();

        return [
            'policies' => collect(Zone::cases())->map(fn (Zone $z) => [
                'zone' => $z->value,
                'label' => $z->label(),
                'unit' => $policies[$z->value]->unit ?? SlaPolicy::HOURS,
                'green' => $policies[$z->value]->green ?? null,
                'orange' => $policies[$z->value]->orange ?? null,
            ])->values(),
            'schedule' => $sla->calendar()->schedule() + ['day_minutes' => $sla->calendar()->dayMinutes()],
            'holidays' => Holiday::where('date', '>=', now()->subYear()->startOfYear())->orderBy('date')->get()
                ->map(fn (Holiday $h) => ['id' => $h->id, 'date' => $h->date->toDateString(), 'name' => $h->name]),
            'default_schedule' => BusinessCalendar::DEFAULT_SCHEDULE,
        ];
    }
}
