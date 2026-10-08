<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Atm;
use App\Models\Bank;
use App\Models\FaultType;
use App\Models\Part;
use App\Models\State;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Export\ConsolidadoExporter;
use App\Services\Reports\ReportService;
use App\Services\Reports\TableExporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reportes para administradores y operadores. Cada uno acepta los filtros
 * from, to, bank_id, state_id, technician_id y ?format=xlsx para descargar.
 */
class ReportController extends Controller
{
    private const MAX_RANGE_DAYS = 731;

    private const HOURS = '[h]:mm';

    private const PERCENT = '0.0" %"';

    private const DATETIME = 'dd/mm/yyyy hh:mm';

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
                $this->fileName('tecnicos', $f), ['mtta' => self::HOURS, 'mttr' => self::HOURS, 'first_visit_rate' => self::PERCENT, 'sla_resolution_rate' => self::PERCENT]);
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
            ], $rows->map(fn ($r) => $r + ['mttr' => $this->excelDuration($r['mttr_ms'])]), $this->fileName('fallas', $f), ['mttr' => self::HOURS, 'close_rate' => self::PERCENT]);
        }

        return response()->json(['data' => $rows, 'filters' => $this->describe($f)]);
    }

    /**
     * Detalle de una cifra del reporte: tickets (por métrica o tipo de falla)
     * o atenciones (de un técnico o con un repuesto). ?format=xlsx descarga.
     */
    public function detail(Request $request): JsonResponse|BinaryFileResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['tickets', 'visits'])],
            'metric' => ['nullable', Rule::in(array_keys(ReportService::TICKET_METRICS))],
            'fault_type_id' => ['nullable', 'integer', 'exists:fault_types,id'],
            'part_id' => ['nullable', 'integer', 'exists:parts,id'],
            'atm_id' => ['nullable', 'integer', 'exists:atms,id'],
            'with_parts' => ['nullable', 'boolean'],
        ]);
        $f = $this->filters($request);
        $extra = array_intersect_key($data, array_flip(['fault_type_id', 'part_id', 'atm_id', 'with_parts']));
        $fault = isset($data['fault_type_id']) ? FaultType::find($data['fault_type_id'])?->name : null;
        $part = isset($data['part_id']) ? Part::find($data['part_id'])?->name : null;

        if ($data['type'] === 'visits') {
            $rows = $this->reports->visitDetail($f, $extra);
            $title = match (true) {
                (bool) $part => "Atenciones con {$part}",
                (bool) $f['technician_id'] => 'Atenciones de '.User::find($f['technician_id'])?->name,
                (bool) ($extra['with_parts'] ?? false) => 'Atenciones con cambio de repuesto',
                default => 'Atenciones del período',
            };
            if ($request->query('format') === 'xlsx') {
                return $this->excel->download($title, $this->describe($f), [
                    'date' => 'Fecha', 'number' => 'N° Ticket', 'atm_number' => 'N° ATM', 'agency_name' => 'Agencia', 'state' => 'Estado',
                    'technician' => 'Técnico', 'fault_type' => 'Falla', 'condition' => 'Estatus final', 'constancia_number' => 'N° Constancia',
                    'parts' => 'Repuestos', 'parts_units' => $part ? "Unid. de {$part}" : 'Repuestos (unid.)', 'work_done' => 'Trabajo realizado',
                ], $rows->map(fn ($r) => $r + ['date' => $this->excelDate($r['finished_at'] ?? $r['visit_date'])]),
                    $this->fileName('atenciones', $f), ['date' => self::DATETIME]);
            }

            return response()->json(['title' => $title, 'type' => 'visits', 'data' => $rows, 'filters' => $this->describe($f)]);
        }

        $metric = $data['metric'] ?? ($fault ? 'fault' : 'opened');
        $rows = $this->reports->ticketDetail($f, $metric, $extra);
        $title = ReportService::TICKET_METRICS[$metric].($fault ? ": {$fault}" : '');
        if ($request->query('format') === 'xlsx') {
            $sla = ['met' => 'Cumplido', 'breached' => 'Vencido', 'at_risk' => 'Por vencer', 'on_track' => 'En tiempo', 'none' => '—'];

            return $this->excel->download($title, $this->describe($f), [
                'number' => 'N° Ticket', 'opened' => 'Apertura', 'closed' => 'Cierre', 'status_label' => 'Estado', 'priority_label' => 'Prioridad',
                'atm_number' => 'N° ATM', 'agency_name' => 'Agencia', 'state' => 'Estado (región)', 'fault_type' => 'Falla', 'technician' => 'Técnico',
                'visits' => 'Visitas', 'arrival' => 'Llegada', 'duration' => 'Resolución', 'sla_response_label' => 'SLA llegada',
                'sla_resolution_label' => 'SLA resolución', 'parts' => 'Repuestos',
            ], $rows->map(fn ($r) => $r + [
                'opened' => $this->excelDate($r['opened_at']),
                'closed' => $this->excelDate($r['closed_at']),
                'arrival' => $this->excelDuration($r['arrival_ms']),
                'duration' => $this->excelDuration($r['duration_ms']),
                'sla_response_label' => $sla[$r['sla_response']],
                'sla_resolution_label' => $sla[$r['sla_resolution']],
            ]), $this->fileName('detalle', $f), [
                'opened' => self::DATETIME, 'closed' => self::DATETIME, 'arrival' => self::HOURS, 'duration' => self::HOURS,
            ]);
        }

        return response()->json(['title' => $title, 'type' => 'tickets', 'metric' => $metric, 'data' => $rows, 'filters' => $this->describe($f)]);
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
        $this->ensureRange($from, $to);

        $path = tempnam(sys_get_temp_dir(), 'consolidado');
        $exporter->save($exporter->build($from, $to, $data['bank_id'] ?? null), $path);
        $name = sprintf('Consolidado Relación de Atenciones %s al %s.xlsx', $from->format('d-m-Y'), $to->format('d-m-Y'));
        app(AuditLogger::class)->record('export.consolidado', 'Descarga a Excel: Libro Consolidado', meta: ['filtros' => 'Del '.$from->format('d/m/Y').' al '.$to->format('d/m/Y')]);

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

        $filters = $this->reports->filters($request->only(['from', 'to', 'bank_id', 'state_id', 'technician_id']));
        $this->ensureRange($filters['from'], $filters['to']);

        return $filters;
    }

    /** Un rango enorme cargaría miles de registros en memoria en el hosting compartido. */
    private function ensureRange(CarbonImmutable $from, CarbonImmutable $to): void
    {
        if ($from->diffInDays($to, absolute: true) > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['from' => 'El período no puede ser mayor a 2 años. Acorte el rango de fechas.']);
        }
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

    /** Fecha ISO → número de fecha de Excel (hora local de la app). */
    private function excelDate(?string $iso): ?float
    {
        return $iso ? Date::PHPToExcel(CarbonImmutable::parse($iso)->setTimezone(config('app.timezone'))->toDateTime()) : null;
    }

    /** Milisegundos → fracción de día (formato de hora de Excel). */
    private function excelDuration(?int $ms): ?float
    {
        return $ms === null ? null : $ms / 86_400_000;
    }
}
