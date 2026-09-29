<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Visit;
use App\Services\Export\ConsolidadoExporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    /** Consumo de repuestos del mes por tipo y por modelo de ATM (RF-4.2). */
    public function parts(Request $request): JsonResponse
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m'], 'bank_id' => ['nullable', 'integer']]);
        $month = CarbonImmutable::createFromFormat('Y-m', $request->query('month', now()->format('Y-m')))->startOfMonth();

        $visits = Visit::query()
            ->with(['parts', 'ticket.atm'])
            ->whereDate('visit_date', '>=', $month->toDateString())
            ->whereDate('visit_date', '<=', $month->endOfMonth()->toDateString())
            ->when($request->query('bank_id'), fn ($q, $bank) => $q->whereHas('ticket', fn ($t) => $t->where('bank_id', $bank)))
            ->get();
        $rows = $visits->flatMap(fn (Visit $v) => $v->parts->map(fn ($p) => [
            'part' => $p->name,
            'model' => $v->ticket->atm->model ?? 'Sin modelo',
            'quantity' => (int) $p->pivot->quantity,
        ]));

        $byPart = $rows->groupBy('part')
            ->map(fn ($g, $part) => ['part' => $part, 'quantity' => $g->sum('quantity')])
            ->sortByDesc('quantity')->values();

        $byModel = $rows->groupBy('model')
            ->map(fn ($g, $model) => [
                'model' => $model,
                'quantity' => $g->sum('quantity'),
                'parts' => $g->groupBy('part')->map(fn ($p, $name) => ['part' => $name, 'quantity' => $p->sum('quantity')])->sortByDesc('quantity')->values(),
            ])
            ->sortByDesc('quantity')->values();

        return response()->json([
            'month' => $month->format('Y-m'),
            'total' => $rows->sum('quantity'),
            'visits' => $visits->count(),
            'visits_with_parts' => $visits->filter(fn (Visit $v) => $v->parts->isNotEmpty())->count(),
            'by_part' => $byPart,
            'by_model' => $byModel,
        ]);
    }

    /** Descarga del consolidado .xlsx (RF-4.3). */
    public function consolidado(Request $request, ConsolidadoExporter $exporter): BinaryFileResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'bank_id' => ['nullable', 'integer', 'exists:banks,id'],
        ]);
        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);

        $path = tempnam(sys_get_temp_dir(), 'consolidado');
        $exporter->save($exporter->build($from, $to, $data['bank_id'] ?? null), $path);
        $name = sprintf('Consolidado Relación de Atenciones %s al %s.xlsx', $from->format('d-m-Y'), $to->format('d-m-Y'));

        return response()->download($path, $name)->deleteFileAfterSend();
    }
}
