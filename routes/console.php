<?php

use App\Models\Bank;
use App\Services\Export\ConsolidadoExporter;
use App\Services\Import\ExcelHistoryImporter;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('servipos:importar-excel
    {archivo : Ruta del consolidado .xlsx}
    {--banco= : Banco al que pertenecen los cajeros del archivo}
    {--simular : Valida y genera el reporte sin guardar nada}', function (ExcelHistoryImporter $importer) {
    $path = $this->argument('archivo');
    $bank = $this->option('banco') ?: $this->ask('¿A qué banco pertenecen los cajeros de este archivo?');
    if (! is_file($path) || ! $bank) {
        $this->error(! is_file($path) ? "No existe el archivo: {$path}" : 'Debe indicar el banco.');

        return 1;
    }

    $dryRun = (bool) $this->option('simular');
    $report = $importer->import($path, $bank, $dryRun);

    $file = 'importacion/reporte-'.now()->format('Ymd-His').($dryRun ? '-simulacion' : '').'.txt';
    Storage::put($file, $report->toText());

    $this->table(['Concepto', 'Cantidad'], collect($report->counts)->map(fn ($n, $k) => [$k, $n])->values());
    $notes = collect($report->notes)->countBy('level');
    $this->line('Observaciones: '.$notes->map(fn ($n, $l) => "{$l}: {$n}")->implode(' · '));
    $this->info(($dryRun ? 'SIMULACIÓN (no se guardó nada). ' : 'Importación completada. ').'Reporte: '.Storage::path($file));

    return 0;
})->purpose('Importa el histórico del consolidado Excel (pestañas Tablas y Base General)');

Artisan::command('servipos:exportar-consolidado
    {--desde= : Fecha inicial (AAAA-MM-DD); por defecto, inicio del mes actual}
    {--hasta= : Fecha final (AAAA-MM-DD); por defecto, fin del mes de --desde}
    {--banco= : Nombre del banco (opcional)}
    {--salida= : Ruta del .xlsx a generar}', function (ConsolidadoExporter $exporter) {
    $from = Carbon::parse($this->option('desde') ?? now()->startOfMonth())->startOfDay();
    $to = $this->option('hasta') ? Carbon::parse($this->option('hasta'))->endOfDay() : $from->copy()->endOfMonth();
    $bankId = $this->option('banco') ? Bank::where('name', $this->option('banco'))->value('id') : null;
    if ($this->option('banco') && ! $bankId) {
        $this->error('No existe el banco '.$this->option('banco'));

        return 1;
    }

    $path = $this->option('salida') ?? storage_path('app/private/exportes/Consolidado '.$from->translatedFormat('F Y').'.xlsx');
    @mkdir(dirname($path), 0775, true);
    $exporter->save($exporter->build($from, $to, $bankId), $path);
    $this->info("Consolidado generado: {$path}");

    return 0;
})->purpose('Exporta las visitas al formato del consolidado Excel actual');
