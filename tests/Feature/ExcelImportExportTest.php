<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Atm;
use App\Models\Bank;
use App\Models\Part;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visit;
use App\Services\Export\ConsolidadoExporter;
use App\Services\Import\ExcelHistoryImporter;
use Database\Seeders\AtmConditionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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

    public function test_bank_is_optional_and_atms_can_be_left_without_one(): void
    {
        $report = app(ExcelHistoryImporter::class)->import($this->file);

        $this->assertFalse($report->hasErrors());
        $this->assertSame(0, Bank::count());
        $this->assertSame(2, Atm::whereNull('bank_id')->count());
        $this->assertSame(2, $report->counts['Cajeros sin banco asignado (se puede indicar después)']);
        $this->assertSame(Ticket::count(), Ticket::whereNull('bank_id')->count());
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

    public function test_dry_run_saves_nothing(): void
    {
        $report = app(ExcelHistoryImporter::class)->import($this->file, 'Banco de Prueba', dryRun: true);

        $this->assertSame(3, $report->counts['Visitas importadas']);
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, Atm::count());
    }

    public function test_loading_the_same_file_again_adds_nothing(): void
    {
        $importer = app(ExcelHistoryImporter::class);
        $importer->import($this->file, 'Banco de Prueba');
        // Una corrección hecha en el sistema no se pisa al volver a cargar el Excel.
        Atm::firstWhere('atm_number', 'A1')->update(['address' => 'Av. Principal, corregida en el sistema']);
        $before = [Atm::count(), Ticket::count(), Visit::count(), Part::count(), User::count()];

        $report = $importer->import($this->file, 'Banco de Prueba');

        $this->assertFalse($report->hasErrors());
        $this->assertSame($before, [Atm::count(), Ticket::count(), Visit::count(), Part::count(), User::count()]);
        $this->assertSame(0, $report->counts['Cajeros nuevos']);
        $this->assertSame(2, $report->counts['Cajeros que ya existían (no se modifican)']);
        $this->assertSame(0, $report->counts['Visitas importadas']);
        $this->assertSame(0, $report->counts['Tickets creados']);
        $this->assertSame(3, $report->counts['Atenciones que ya estaban cargadas (se omiten)']);
        $this->assertSame('Av. Principal, corregida en el sistema', Atm::firstWhere('atm_number', 'A1')->address);
        $this->assertSame([], array_values(array_filter($report->notes, fn (array $note) => str_contains($note['where'], 'fila'))), 'las filas ya cargadas no vuelven a generar avisos');
    }

    public function test_a_newer_file_adds_only_the_new_atms_and_visits(): void
    {
        $importer = app(ExcelHistoryImporter::class);
        $importer->import($this->file, 'Banco de Prueba');
        $pending = Ticket::whereRelation('atm', 'atm_number', 'A2')->sole();
        $this->assertSame(TicketStatus::PendingPart, $pending->status);

        // El cliente siguió llenando el Excel: un cajero nuevo, el seguimiento del cajero A2 que
        // estaba pendiente, una atención sin constancia y una fila repetida de esa misma atención.
        $this->writeFixture($this->file, extended: true);
        $report = $importer->import($this->file, 'Banco de Prueba');

        $this->assertFalse($report->hasErrors());
        $this->assertSame(1, $report->counts['Cajeros nuevos']);
        $this->assertSame(2, $report->counts['Cajeros que ya existían (no se modifican)']);
        $this->assertSame(3, $report->counts['Atenciones que ya estaban cargadas (se omiten)']);
        $this->assertSame(4, $report->counts['Visitas importadas']);
        $this->assertSame(3, $report->counts['Tickets creados']);
        $this->assertSame(3, Atm::count());
        $this->assertSame(7, Visit::count());

        // El seguimiento se sumó al ticket que A2 tenía pendiente de la carga anterior y lo concluyó.
        $pending->refresh();
        $this->assertSame(2, $pending->visits()->count());
        $this->assertSame(TicketStatus::Closed, $pending->status);
        $this->assertSame(1, Ticket::whereRelation('atm', 'atm_number', 'A2')->count());

        // Volver a cargar ese mismo archivo ya no agrega nada, ni siquiera las filas sin constancia.
        $again = $importer->import($this->file, 'Banco de Prueba');
        $this->assertSame(0, $again->counts['Visitas importadas']);
        $this->assertSame(7, $again->counts['Atenciones que ya estaban cargadas (se omiten)']);
        $this->assertSame(7, Visit::count());
    }

    public function test_a_visit_registered_in_the_app_is_not_duplicated_by_the_excel(): void
    {
        $importer = app(ExcelHistoryImporter::class);
        $importer->import($this->file, 'Banco de Prueba');
        // El técnico ya registró en el sistema la atención que el Excel trae después (mismo cajero, fecha y constancia).
        $live = Ticket::create([
            'number' => 'TK-2026-09000', 'atm_id' => Atm::firstWhere('atm_number', 'A1')->id, 'status' => TicketStatus::Closed,
            'opened_at' => '2026-09-02 09:00:00', 'closed_at' => '2026-09-02 11:00:00',
        ]);
        $live->visits()->create(['visit_date' => '2026-09-02', 'constancia_number' => '200', 'work_done' => 'Registrada desde la app']);

        $this->writeFixture($this->file, extended: true);
        $report = $importer->import($this->file, 'Banco de Prueba');

        $this->assertSame(4, $report->counts['Atenciones que ya estaban cargadas (se omiten)']);
        $this->assertSame(1, Visit::where('constancia_number', '200')->count());
        $this->assertSame(1, $live->visits()->count(), 'el ticket abierto en el sistema no se toca');
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

    private function writeFixture(string $path, bool $extended = false): void
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

        if ($extended) {
            $tables->fromArray([3, 'AGENCIA', 'A3', 'SER3', 70, 'MIRANDA', 'CHACAO', 'DIEBOLD', 'OPTEVA562', 'Av. Francisco de Miranda'], null, 'B5');
            $base->fromArray([4, $date('2026-09-02'), 'A1', null, null, null, null, 200, 'Falla de Dispensación', 'No', null, null, 'Limpieza general', 'Operativo', 'Jesus Ramos'], null, 'B6');
            $base->fromArray([5, $date('2026-09-05'), 'A2', null, null, null, null, 201, 'Pendiente por Repuesto', 'Si', 'PICKER', 1, 'Se instala el repuesto', 'Operativo', 'Jesus Ramos'], null, 'B7');
            $base->fromArray([6, $date('2026-09-08'), 'A3', null, null, null, null, null, 'Falla de Dispensación', 'No', null, null, 'Revisión sin constancia', 'Operativo', 'Jesus Ramos'], null, 'B8');
            $base->fromArray([7, $date('2026-09-08'), 'A3', null, null, null, null, null, 'Falla de Dispensación', 'No', null, null, 'Revisión sin constancia', 'Operativo', 'Jesus Ramos'], null, 'B9');
        }

        (new Xlsx($book))->save($path);
    }
}
