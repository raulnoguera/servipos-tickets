<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Import\ExcelHistoryImporter;
use App\Services\Import\ImportException;
use App\Services\Inbound\InboundMailProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Importación del consolidado histórico desde el panel (RNF Migración). */
class ImportController extends Controller
{
    public function __invoke(Request $request, ExcelHistoryImporter $importer, AuditLogger $audit, InboundMailProcessor $processor): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:20480'],
            // Opcional: el Excel no indica el banco. Sin él, los cajeros quedan sin banco y se asigna después.
            'bank' => ['nullable', 'string', 'max:255'],
            'simulate' => ['boolean'],
        ]);

        try {
            $report = $importer->import($request->file('file')->getRealPath(), $data['bank'] ?? null, $request->boolean('simulate'));
        } catch (ImportException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        } catch (Throwable $e) {
            // Errores de la base de datos o de la librería de Excel: al log completos, al usuario un mensaje genérico.
            report($e);
            throw ValidationException::withMessages(['file' => 'No se pudo procesar el archivo. Verifique que sea el Libro Consolidado en formato .xlsx y que no tenga datos repetidos.']);
        }
        // Con los cajeros recién cargados, los reportes del banco que esperaban por ellos ya pueden abrir su ticket.
        $emails = $request->boolean('simulate') || $report->hasErrors() ? null : $processor->reevaluatePending();
        $audit->record(
            'import.excel',
            ($request->boolean('simulate') ? 'Simulación de importación' : 'Importación').' del Excel histórico · '.$request->file('file')->getClientOriginalName(),
            meta: ['banco' => $data['bank'] ?? 'sin indicar', 'conteos' => $report->counts],
        );

        return response()->json([
            'simulated' => $request->boolean('simulate'),
            'counts' => $report->counts,
            'notes' => $report->notes,
            'has_errors' => $report->hasErrors(),
            'emails' => $emails,
        ]);
    }
}
