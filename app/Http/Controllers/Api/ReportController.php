<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Atm;
use App\Models\Bank;
use App\Models\State;
use App\Models\User;
use App\Services\Export\ConsolidadoExporter;
use App\Services\Reports\ReportService;
use App\Services\Reports\TableExporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reportes para administradores y operadores. Cada uno acepta los filtros
 * from, to, bank_id, state_id, technician_id y ?format=xlsx para descargar.
 */
class ReportController extends Controller
{
    private const HOURS = '[h]:mm';

    public function __construct(private ReportService $reports, private TableExporter $excel) {}

    public function summary(Request $request): JsonResponse
    {
        $f = $this->filters($request);

        return response()->json($this->reports->summary($f) + ['filters' => $this->describe($f)]);
    }

    public function atms(Request $request): JsonResponse|BinaryFileResponse
    {
        $f = $this->filters($request);
        $rows = $this->reports->atms($f);

        if ($request->query('format') === 'xlsx') {
            return $this->excel->download('Reporte por cajero', $this->describe($f), [
                'atm_number' => 'N° ATM', 'agency_code' => 'Cód. agencia', 'agency_name' => 'Agencia', 'state' => 'Estado',
                'bank' => 'Banco', 'model' => 'Modelo', 'tickets' => 'Tickets', 'reincidences' => 'Reincidencias', 'open' => 'Abiertos',
                'visits' => 'Visitas', 'parts_units' => 'Repuestos (unid.)', 'top_fault' => 'Falla más frecuente', 'mttr' => 'Tiempo prom. resolución',
            ], $rows->map(fn ($r) => $r + ['mttr' => $this->excelDuration($r['mttr_ms'])]), $this->fileName('cajeros', $f), ['mttr' => self::HOURS]);
        }

        return response()->json(['data' => $rows, 'filters' => $this->describe($f)]);
    }

    public function atm(Atm $atm): JsonResponse
    {
        return response()->json(['atm' => $atm->load(['state', 'bank'])] + $this->reports->atmHistory($atm));
    }

    public function technicians(Request $request): JsonResponse|BinaryFileResponse
    {
        $f = $this->filters($request);
        $rows = $this->reports->technicians($f);

        if ($request->query('format') === 'xlsx') {
            return $this->excel->download('Reporte por técnico', $this->describe($f), [
                'name' => 'Técnico', 'assigned' => 'Asignados', 'closed' => 'Concluidos', 'pending_now' => 'Pendientes hoy',
                'visits' => 'Visitas', 'parts_units' => 'Repuestos (unid.)', 'mtta' => 'Tiempo prom. llegada', 'mttr' => 'Tiempo prom. resolución',
                'first_visit_rate' => '% resuelto 1.ª visita', 'sla_resolution_rate' => '% SLA resolución',
            ], $rows->map(fn ($r) => $r + ['mtta' => $this->excelDuration($r['mtta_ms']), 'mttr' => $this->excelDuration($r['mttr_ms'])]),
                $this->fileName('tecnicos', $f), ['mtta' => self::HOURS, 'mttr' => self::HOURS]);
        }

        return response()->json(['data' => $rows, 'filters' => $this->describe($f)]);
    }

    public function parts(Request $request): JsonResponse|BinaryFileResponse
    {
        $f = $this->filters($request);
        $data = $this->reports->parts($f);

        if ($request->query('format') === 'xlsx') {
            return $this->excel->download('Consumo de repuestos', $this->describe($f), [
                'name' => 'Repuesto', 'quantity' => 'Unidades', 'atms' => 'Cajeros distintos',
            ], $data['by_part'], $this->fileName('repuestos', $f));
        }

        return response()->json($data + ['filters' => $this->describe($f)]);
    }

    public function faults(Request $request): JsonResponse|BinaryFileResponse
    {
        $f = $this->filters($request);
        $rows = $this->reports->faults($f);

        if ($request->query('format') === 'xlsx') {
            return $this->excel->download('Reporte por tipo de falla', $this->describe($f), [
                'name' => 'Tipo de falla', 'tickets' => 'Tickets', 'closed' => 'Concluidos', 'pending' => 'Pendientes',
                'close_rate' => '% concluidos', 'atms' => 'Cajeros afectados', 'mttr' => 'Tiempo prom. resolución',
            ], $rows->map(fn ($r) => $r + ['mttr' => $this->excelDuration($r['mttr_ms'])]), $this->fileName('fallas', $f), ['mttr' => self::HOURS]);
        }

        return response()->json(['data' => $rows, 'filters' => $this->describe($f)]);
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

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'bank_id' => ['nullable', 'integer'],
            'state_id' => ['nullable', 'integer'],
            'technician_id' => ['nullable', 'integer'],
        ]);

        return $this->reports->filters($request->only(['from', 'to', 'bank_id', 'state_id', 'technician_id']));
    }

    private function describe(array $f): string
    {
        return implode(' · ', array_filter([
            'Del '.$f['from']->format('d/m/Y').' al '.$f['to']->format('d/m/Y'),
            $f['bank_id'] ? 'Banco: '.Bank::find($f['bank_id'])?->name : null,
            $f['state_id'] ? 'Estado: '.State::find($f['state_id'])?->name : null,
            $f['technician_id'] ? 'Técnico: '.User::find($f['technician_id'])?->name : null,
        ]));
    }

    private function fileName(string $kind, array $f): string
    {
        return sprintf('Reporte %s %s al %s.xlsx', $kind, $f['from']->format('d-m-Y'), $f['to']->format('d-m-Y'));
    }

    /** Milisegundos → fracción de día (formato de hora de Excel). */
    private function excelDuration(?int $ms): ?float
    {
        return $ms === null ? null : $ms / 86_400_000;
    }
}
