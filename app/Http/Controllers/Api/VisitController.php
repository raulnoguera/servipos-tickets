<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVisitRequest;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\Visit;
use App\Services\TicketService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VisitController extends Controller
{
    public function store(StoreVisitRequest $request, Ticket $ticket, TicketService $tickets): TicketResource
    {
        Gate::authorize('work', $ticket);

        $tickets->registerVisit($ticket, $request->validated(), $request->user(), $request->file('photo'));

        return new TicketResource($ticket->refresh()->load([
            'atm.state', 'bank', 'faultType', 'technician', 'opener',
            'visits.parts', 'visits.technician', 'visits.faultType', 'visits.atmCondition', 'events.user',
        ]));
    }

    /** Foto de la constancia; solo para quien puede ver el ticket. */
    public function constancia(Visit $visit): StreamedResponse
    {
        Gate::authorize('view', $visit->ticket);
        abort_unless($visit->constancia_path && Storage::exists($visit->constancia_path), 404);

        return Storage::response($visit->constancia_path, null, ['Cache-Control' => 'private, max-age=86400']);
    }
}
