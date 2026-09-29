<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Import\ExcelHistoryImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Importación del consolidado histórico desde el panel (RNF Migración). */
class ImportController extends Controller
{
    public function __invoke(Request $request, ExcelHistoryImporter $importer): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:20480'],
            'bank' => ['required', 'string', 'max:255'],
            'simulate' => ['boolean'],
        ]);

        try {
            $report = $importer->import($request->file('file')->getRealPath(), $data['bank'], $request->boolean('simulate'));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return response()->json([
            'simulated' => $request->boolean('simulate'),
            'counts' => $report->counts,
            'notes' => $report->notes,
            'has_errors' => $report->hasErrors(),
        ]);
    }
}
