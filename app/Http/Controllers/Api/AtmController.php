<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveAtmRequest;
use App\Http\Resources\AtmResource;
use App\Models\Atm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AtmController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $term = trim((string) $request->query('q'));

        $atms = Atm::query()
            ->with(['bank', 'state'])
            ->withCount('openTickets')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('atm_number', 'like', "%{$term}%")
                ->orWhere('serial', 'like', "%{$term}%")
                ->orWhere('agency_name', 'like', "%{$term}%")
                ->orWhere('agency_code', $term)))
            ->when($request->query('bank_id'), fn ($q, $bank) => $q->where('bank_id', $bank))
            ->when($request->query('state_id'), fn ($q, $state) => $q->where('state_id', $state))
            ->orderBy('atm_number')
            ->paginate(min(100, (int) $request->query('per_page', 25)));

        return AtmResource::collection($atms);
    }

    /** Autocompletado del N° de ATM en la apertura (RF-1.1). */
    public function lookup(string $number): AtmResource
    {
        $atm = Atm::with(['bank', 'state'])->withCount('openTickets')
            ->where('atm_number', strtoupper(trim($number)))
            ->firstOrFail();

        return new AtmResource($atm);
    }

    public function store(SaveAtmRequest $request): AtmResource
    {
        return new AtmResource(Atm::create($request->validated())->load(['bank', 'state']));
    }

    public function update(SaveAtmRequest $request, Atm $atm): AtmResource
    {
        $atm->update($request->validated());

        return new AtmResource($atm->load(['bank', 'state']));
    }
}
