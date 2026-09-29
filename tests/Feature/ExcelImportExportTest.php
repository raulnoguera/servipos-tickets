<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Atm;
use App\Models\Part;
use App\Models\Ticket;
use App\Services\Export\ConsolidadoExporter;
use App\Services\Import\ExcelHistoryImporter;
use Database\Seeders\AtmConditionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

class ExcelImportExportTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AtmConditionSeeder::class);
        $this->file = tempnam(sys_get_temp_dir(), 'consolidado').'.xlsx';
        $this->writeFixture($this->file);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function test_imports_catalogs_atms_and_groups_follow_up_visits_into_one_ticket(): void
    {
        $report = app(ExcelHistoryImporter::class)->import($this->file, 'Banco de Prueba');

        $this->assertFalse($report->hasErrors());
        $this->assertSame(2, Atm::count());
        $this->assertSame('Portuguesa', Atm::firstWhere('atm_number', 'A2')->state->name);
        $this->assertSame('OPTEVA 562', Atm::firstWhere('atm_number', 'A2')->model);
        $this->assertNull(Atm::firstWhere('atm_number', 'A1')->agency_code, 'código igual al serial se descarta');

        // "Picker" y "PICKER" son el mismo repuesto.
        $this->assertSame(1, Part::where('name', 'PICKER')->count());

        // A1: visita que deja pendiente + visita de seguimiento = un solo ticket, concluido.
        $a1 = Ticket::whereRelation('atm', 'atm_number', 'A1')->get();
        $this->assertCount(1, $a1);
        $this->assertCount(2, $a1->first()->visits);
        $this->assertSame(TicketStatus::Closed, $a1->first()->status);
        $this->assertNull($a1->first()->duration_ms, 'el histórico no tiene horas');

        // A2: queda pendiente por repuesto.
        $this->assertSame(TicketStatus::PendingPart, Ticket::whereRelation('atm', 'atm_number', 'A2')->first()->status);
        $this->assertSame(['TK-2026-00001', 'TK-2026-00002'], Ticket::orderBy('id')->pluck('number')->all());
    }

    public function test_dry_run_saves_nothing_and_second_import_is_refused(): void
    {
        $importer = app(ExcelHistoryImporter::class);

        $importer->import($this->file, 'Banco de Prueba', dryRun: true);
        $this->assertSame(0, Ticket::count());

        $importer->import($this->file, 'Banco de Prueba');
        $this->expectException(RuntimeException::class);
        $importer->import($this->file, 'Banco de Prueba');
    }

    public function test_export_matches_the_consolidado_layout(): void
    {
        app(ExcelHistoryImporter::class)->import($this->file, 'Banco de Prueba');
        $visit = Ticket::whereRelation('atm', 'atm_number', 'A2')->first()->visits->first();
        $visit->parts()->attach(Part::firstOrCreate(['name' => 'FEEDSHAFT'])->id, ['quantity' => 1]);

        $sheet = app(ConsolidadoExporter::class)
            ->build(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'))
            ->getSheetByName('Base General');

        $this->assertSame('N°', $sheet->getCell('B2')->getValue());
        $this->assertSame('Mes', $sheet->getCell('Q2')->getValue());
        $this->assertSame('N° Ticket', $sheet->getCell('R2')->getValue());
        $this->assertSame(3, $sheet->getHighestDataRow() - 2, 'una fila por visita, incluida la del último día del mes');

        $row = collect(range(3, 5))->first(fn ($r) => $sheet->getCell("D{$r}")->getValue() === 'A2');
        $this->assertSame('PICKER x2, FEEDSHAFT x1', $sheet->getCell("L{$row}")->getValue());
        $this->assertSame(3, $sheet->getCell("M{$row}")->getValue());
        $this->assertSame('Operativo / Pendiente por Repuesto', $sheet->getCell("O{$row}")->getValue());
    }

    private function writeFixture(string $path): void
    {
        $book = new Spreadsheet;

        $tables = $book->getActiveSheet()->setTitle('Tablas');
        $tables->fromArray(['#', 'Tipo', 'N° ATM´s', 'Serial de Equipo', 'Código de Agencia', 'Estado', 'Nombre de Agencia', 'Marca', 'Modelo', 'Direccion'], null, 'B2');
        $tables->fromArray([1, 'AGENCIA', 'A1', 'SER1', 'SER1', 'MIRANDA', 'ALTAMIRA', 'DIEBOLD', null, null], null, 'B3');
        $tables->fromArray([2, 'AGENCIA', 'A2', 'SER2', 63, 'POTUGUESA', 'ACARIGUA', 'DIEBOLD', 'OPTEVA562', null], null, 'B4');
        $tables->setCellValue('V2', 'MOTIVOS DE VISITAS')->setCellValue('V3', 'Falla de Dispensación')->setCellValue('V4', 'Pendiente por Repuesto');
        $tables->setCellValue('X2', 'ESTATUS FINAL')->setCellValue('X3', 'Operativo');
        $tables->setCellValue('AB2', 'Tecnico')->setCellValue('AB3', 'Jesus Ramos ');
        $tables->setCellValue('AD2', 'Repuesto Utilizado')->setCellValue('AD3', 'PICKER');

        $base = $book->createSheet()->setTitle('Base General');
        $base->fromArray(['N°', 'Fecha de Reporte', 'N° ATM´s', 'Serial de Equipo', 'Código de Agencia', 'Estado', 'Nombre de Agencia', 'N° Constancia', 'Reporte de Falla', 'Cambio de Repuesto', 'Repuesto Utilizado', 'Cantidad de Repuestos', 'Trabajo realizado/Observacion', 'Estatus Final', 'Tecnico', 'Mes'], null, 'B2');
        $date = fn (string $d) => ExcelDate::PHPToExcel(Carbon::parse($d));
        $base->fromArray([1, $date('2026-08-03'), 'A1', null, null, null, null, 100, 'Falla de Dispensación', 'No', null, null, 'Pendiente por cassette', 'Operativo / Pendiente por Repuesto', 'Jesus Ramos'], null, 'B3');
        $base->fromArray([2, $date('2026-08-10'), 'A2', null, null, null, null, 101, 'Falla de Dispensación', 'Si', 'Picker', 2, 'Cambio de picker', 'Operativo / Pendiente por Repuesto', 'Jesus Ramos'], null, 'B4');
        $base->fromArray([3, $date('2026-08-31'), 'A1', null, null, null, null, 102, 'Pendiente por Repuesto', 'Si', 'PICKER', 1, 'Se instala cassette', 'Operativo', 'Jesus Ramos'], null, 'B5');

        (new Xlsx($book))->save($path);
    }
}
