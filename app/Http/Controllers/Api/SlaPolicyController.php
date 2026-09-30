<?php

namespace App\Http\Controllers\Api;

use App\Enums\Priority;
use App\Http\Controllers\Controller;
use App\Models\SlaPolicy;
use App\Services\SlaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Metas de SLA por prioridad (en minutos). */
class SlaPolicyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->list()]);
    }

    public function update(Request $request, SlaService $sla): JsonResponse
    {
        $data = $request->validate([
            'policies' => ['required', 'array'],
            'policies.*.priority' => ['required', Rule::enum(Priority::class)],
            'policies.*.response_minutes' => ['nullable', 'integer', 'min:1', 'max:43200'],
            'policies.*.resolution_minutes' => ['nullable', 'integer', 'min:1', 'max:86400'],
        ]);
        foreach ($data['policies'] as $policy) {
            SlaPolicy::updateOrCreate(['priority' => $policy['priority']], [
                'response_minutes' => $policy['response_minutes'] ?? null,
                'resolution_minutes' => $policy['resolution_minutes'] ?? null,
            ]);
        }
        $sla->forget();

        return response()->json(['data' => $this->list()]);
    }

    /** @return list<array<string, mixed>> */
    private function list(): array
    {
        $policies = SlaPolicy::all()->keyBy(fn ($p) => $p->priority->value);

        return collect(Priority::cases())->sortByDesc(fn (Priority $p) => $p->weight())->map(fn (Priority $p) => [
            'priority' => $p->value,
            'label' => $p->label(),
            'response_minutes' => $policies[$p->value]->response_minutes ?? null,
            'resolution_minutes' => $policies[$p->value]->resolution_minutes ?? null,
        ])->values()->all();
    }
}
