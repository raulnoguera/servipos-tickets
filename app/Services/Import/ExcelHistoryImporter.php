<?php

namespace App\Services\Import;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Atm;
use App\Models\AtmCondition;
use App\Models\Bank;
use App\Models\FaultType;
use App\Models\Part;
use App\Models\State;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Importa el "Libro Consolidado Relación de Atenciones" (pestañas "Tablas" y
 * "Base General") al esquema del sistema.
 *
 * Cada fila de "Base General" se convierte en una visita. Las filas cuyo
 * "Reporte de Falla" es "Pendiente por Repuesto" se agregan al ticket
 * pendiente del mismo cajero; el resto abre un ticket nuevo. El histórico no
 * trae horas, así que sus tickets no tienen duración (no cuentan para el SLA).
 */
class ExcelHistoryImporter
{
    private const HEADER_ROW = 2;

    private const MAX_ROWS = 20000;

    /** Filas vacías seguidas que indican el final de los datos. */
    private const EMPTY_ROWS_TO_STOP = 30;

    private const FOLLOW_UP_FAULT = 'PENDIENTE POR REPUESTO';

    private ImportReport $report;

    private Bank $bank;

    /** @var array<string, State> */
    private array $states = [];

    /** @var array<string, FaultType> */
    private array $faultTypes = [];

    /** @var array<string, Part> */
    private array $parts = [];

    /** @var array<string, AtmCondition> */
    private array $conditions = [];

    /** @var array<string, User> */
    private array $technicians = [];

    /** @var array<string, Atm> */
    private array $atms = [];

    /** @var array<string, true> correcciones ya anotadas en el reporte */
    private array $reportedFixes = [];

    public function __construct(private TicketNumberGenerator $numbers) {}

    public function import(string $path, string $bankName, bool $dryRun = false): ImportReport
    {
        $this->report = new ImportReport;
        $this->states = $this->faultTypes = $this->parts = $this->conditions = [];
        $this->technicians = $this->atms = $this->reportedFixes = [];

        if (Ticket::where('source', 'import')->exists()) {
            throw new RuntimeException('Ya existe un histórico importado. Para repetir la importación, limpie la base de datos (php artisan migrate:fresh --seed).');
        }

        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new class(self::MAX_ROWS) implements IReadFilter
        {
            public function __construct(private int $maxRow) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= $this->maxRow;
            }
        });
        $book = $reader->load($path);

        $tables = $book->getSheetByName('Tablas') ?? throw new RuntimeException('No se encontró la pestaña "Tablas".');
        $base = $book->getSheetByName('Base General') ?? throw new RuntimeException('No se encontró la pestaña "Base General".');

        DB::beginTransaction();
        try {
            $this->bank = Bank::firstOrCreate(['name' => $bankName]);
            $this->loadCatalogs($tables);
            $this->loadAtms($tables);
            $this->loadVisits($base);
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $this->report;
    }

    private function loadCatalogs(Worksheet $sheet): void
    {
        $cols = $this->headers($sheet);

        foreach ($this->column($sheet, $cols, 'ESTADOS') as $row => $value) {
            $this->state($value, "Tablas!ESTADOS fila {$row}");
        }
        foreach ($this->column($sheet, $cols, 'MOTIVOS DE VISITAS') as $value) {
            $this->faultType($value);
        }
        foreach ($this->column($sheet, $cols, 'Repuesto Utilizado') as $row => $value) {
            $this->part($value, "Tablas!Repuesto fila {$row}");
        }
        AtmCondition::all()->each(fn (AtmCondition $c) => $this->conditions[Normalizer::key($c->name)] = $c);
        foreach ($this->column($sheet, $cols, 'ESTATUS FINAL') as $value) {
            $this->condition($value, 'Tablas!ESTATUS FINAL');
        }
        foreach ($this->column($sheet, $cols, 'Tecnico') as $row => $value) {
            $this->technician($value, "Tablas!Tecnico fila {$row}");
        }
    }

    private function loadAtms(Worksheet $sheet): void
    {
        $cols = $this->headers($sheet);
        $col = fn (string $header) => $cols[Normalizer::key($header)] ?? throw new RuntimeException("Falta la columna \"{$header}\" en Tablas.");

        foreach ($this->rows($sheet, $col('N° ATM´s')) as $row) {
            $where = "Tablas fila {$row}";
            $get = fn (string $header) => $sheet->getCell($col($header).$row)->getValue();

            $number = Str::upper(Normalizer::text($get('N° ATM´s')));
            $serial = Normalizer::text($get('Serial de Equipo'));
            if ($serial !== null && str_contains($serial, ' ')) {
                $this->report->fix($where, "Serial \"{$serial}\" con espacios → \"".str_replace(' ', '', $serial).'"');
                $serial = str_replace(' ', '', $serial);
            }

            $agencyCode = Normalizer::text($get('Código de Agencia'));
            if ($agencyCode !== null && $agencyCode === $serial) {
                $this->report->fix($where, "ATM {$number}: el código de agencia era igual al serial; se dejó vacío para corregir en el maestro.");
                $agencyCode = null;
            } elseif ($agencyCode !== null && ! ctype_digit($agencyCode)) {
                $this->report->warn($where, "ATM {$number}: código de agencia no numérico \"{$agencyCode}\".");
            } elseif ($agencyCode === null) {
                $this->report->warn($where, "ATM {$number}: sin código de agencia.");
            }

            $rawModel = Normalizer::text($get('Modelo'));
            $model = Normalizer::model($rawModel);
            if ($rawModel !== null && $rawModel !== $model) {
                $this->report->fix($where, "Modelo \"{$rawModel}\" → \"{$model}\"");
            }

            $brand = Normalizer::text($get('Marca'));
            if ($brand === null) {
                $this->report->warn($where, "ATM {$number}: sin marca.");
            }

            if (isset($this->atms[$number])) {
                $this->report->warn($where, "ATM {$number} repetido en el maestro; se conserva el primero.");

                continue;
            }

            $this->atms[$number] = Atm::create([
                'atm_number' => $number,
                'serial' => $serial,
                'bank_id' => $this->bank->id,
                'state_id' => $this->state($get('Estado'), $where)?->id,
                'agency_code' => $agencyCode,
                'agency_name' => Normalizer::text($get('Nombre de Agencia')),
                'site_type' => Normalizer::text($get('Tipo')),
                'brand' => $brand ? Str::upper($brand) : null,
                'model' => $model,
                'address' => Normalizer::text($get('Direccion')),
            ]);
            $this->report->count('Cajeros importados');
        }

        $this->report->count('Cajeros sin modelo', Atm::whereNull('model')->count());
        $this->report->count('Cajeros sin dirección', Atm::whereNull('address')->count());
    }

    private function loadVisits(Worksheet $sheet): void
    {
        $cols = $this->headers($sheet);
        $col = fn (string $header) => $cols[Normalizer::key($header)] ?? throw new RuntimeException("Falta la columna \"{$header}\" en Base General.");

        // Se leen todas las filas primero para procesarlas en orden cronológico.
        $rows = [];
        foreach ($this->rows($sheet, $col('N° ATM´s')) as $row) {
            $get = fn (string $header) => $sheet->getCell($col($header).$row)->getValue();
            $rows[] = [
                'row' => $row,
                'date' => $this->date($get('Fecha de Reporte')),
                'atm' => Str::upper(Normalizer::text($get('N° ATM´s'))),
                'constancia' => Normalizer::text($get('N° Constancia')),
                'fault' => $get('Reporte de Falla'),
                'changed' => Normalizer::yesNo($get('Cambio de Repuesto')),
                'part' => $get('Repuesto Utilizado'),
                'quantity' => $get('Cantidad de Repuestos'),
                'work' => Normalizer::text($get('Trabajo realizado/Observacion')),
                'condition' => $get('Estatus Final'),
                'technician' => $get('Tecnico'),
            ];
        }

        $this->flagDateOutliers($rows);
        $this->flagDuplicateConstancias($rows);

        usort($rows, fn ($a, $b) => [$a['date'], $a['row']] <=> [$b['date'], $b['row']]);

        /** @var array<int, Ticket> $pendingByAtm tickets pendientes por cajero */
        $pendingByAtm = [];

        foreach ($rows as $r) {
            $where = "Base General fila {$r['row']}";

            $atm = $this->atms[$r['atm']] ?? null;
            if (! $atm) {
                $this->report->error($where, "ATM {$r['atm']} no existe en el maestro; fila omitida.");

                continue;
            }
            if (! $r['date']) {
                $this->report->error($where, 'Fecha de reporte inválida; fila omitida.');

                continue;
            }

            $fault = $this->faultType($r['fault']);
            $condition = $this->condition($r['condition'], $where);
            $technician = $this->technician($r['technician'], $where);
            if ($r['constancia'] === null) {
                $this->report->warn($where, "ATM {$r['atm']}: sin N° de constancia.");
            }

            $ticket = null;
            if (Normalizer::key($fault?->name) === self::FOLLOW_UP_FAULT && isset($pendingByAtm[$atm->id])) {
                $ticket = $pendingByAtm[$atm->id];
                $this->report->count('Visitas de seguimiento agregadas a un ticket');
            } else {
                $ticket = Ticket::create([
                    'number' => $this->numbers->next($r['date']->year),
                    'atm_id' => $atm->id,
                    'bank_id' => $atm->bank_id,
                    'fault_type_id' => $fault?->id,
                    'status' => TicketStatus::Assigned,
                    'source' => 'import',
                    'assigned_to' => $technician?->id,
                    'opened_at' => $r['date'],
                    'assigned_at' => $r['date'],
                ]);
                $this->report->count('Tickets creados');
            }

            $visit = $ticket->visits()->create([
                'technician_id' => $technician?->id,
                'fault_type_id' => $fault?->id,
                'constancia_number' => $r['constancia'],
                'visit_date' => $r['date'],
                'work_done' => $r['work'],
                'parts_changed' => (bool) $r['changed'],
                'atm_condition_id' => $condition?->id,
            ]);
            $this->report->count('Visitas importadas');

            $part = $this->part($r['part'], $where);
            $quantity = is_numeric($r['quantity']) ? (int) $r['quantity'] : null;
            if ($part) {
                if (! $quantity) {
                    $this->report->warn($where, "Repuesto {$part->name} sin cantidad; se registra 1.");
                }
                $visit->parts()->attach($part->id, ['quantity' => $quantity ?: 1]);
                if (! $r['changed']) {
                    $this->report->fix($where, 'Tiene repuesto pero "Cambio de Repuesto" no era "Si"; se marcó como Sí.');
                    $visit->update(['parts_changed' => true]);
                }
                $this->report->count('Repuestos registrados (unidades)', $quantity ?: 1);
            } elseif ($r['changed']) {
                $this->report->warn($where, '"Cambio de Repuesto" = Si pero no indica el repuesto.');
            }

            $status = $condition?->resulting_ticket_status ?? TicketStatus::PendingValidation;
            $ticket->update([
                'status' => $status,
                'assigned_to' => $technician?->id ?? $ticket->assigned_to,
                'closed_at' => $status === TicketStatus::Closed ? $r['date'] : null,
            ]);
            $ticket->events()->create([
                'to_status' => $status->value,
                'note' => "Importado del consolidado Excel ({$where})",
                'created_at' => now(),
            ]);

            if ($status === TicketStatus::Closed) {
                unset($pendingByAtm[$atm->id]);
            } else {
                $pendingByAtm[$atm->id] = $ticket;
            }
        }

        $this->report->count('Tickets que quedan pendientes', count($pendingByAtm));
    }

    /** @param list<array<string, mixed>> $rows */
    private function flagDateOutliers(array $rows): void
    {
        $timestamps = array_values(array_filter(array_map(fn ($r) => $r['date']?->timestamp, $rows)));
        if (! $timestamps) {
            return;
        }
        sort($timestamps);
        $median = CarbonImmutable::createFromTimestamp($timestamps[intdiv(count($timestamps), 2)]);
        foreach ($rows as $r) {
            if ($r['date'] && abs($r['date']->diffInDays($median)) > 45) {
                $this->report->warn("Base General fila {$r['row']}", "Fecha {$r['date']->format('d/m/Y')} muy distinta al resto del período (¿error de tipeo?). Se importa tal cual.");
            }
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function flagDuplicateConstancias(array $rows): void
    {
        $seen = [];
        foreach ($rows as $r) {
            if ($r['constancia'] === null) {
                continue;
            }
            $key = $r['atm'].'_'.$r['constancia'];
            if (isset($seen[$key])) {
                $this->report->warn("Base General fila {$r['row']}", "ATM {$r['atm']} con la constancia {$r['constancia']} repetida (también en la fila {$seen[$key]}).");
            }
            $seen[$key] ??= $r['row'];
        }
    }

    /** Anota una corrección repetitiva solo la primera vez que aparece. */
    private function fixOnce(string $where, string $message): void
    {
        if (! isset($this->reportedFixes[$message])) {
            $this->reportedFixes[$message] = true;
            $this->report->fix($where, $message.' (y demás apariciones)');
        }
    }

    /** @return array<string, string> clave normalizada del encabezado → letra de columna (primera aparición) */
    private function headers(Worksheet $sheet): array
    {
        $map = [];
        $last = Coordinate::columnIndexFromString($sheet->getHighestDataColumn(self::HEADER_ROW));
        for ($i = 1; $i <= $last; $i++) {
            $letter = Coordinate::stringFromColumnIndex($i);
            $value = Normalizer::text($sheet->getCell($letter.self::HEADER_ROW)->getValue());
            if ($value !== null) {
                $map[Normalizer::key($value)] ??= $letter;
            }
        }

        return $map;
    }

    /** @return \Generator<int, int> números de fila con dato en la columna clave */
    private function rows(Worksheet $sheet, string $keyColumn): \Generator
    {
        $empty = 0;
        for ($row = self::HEADER_ROW + 1; $row <= self::MAX_ROWS && $empty < self::EMPTY_ROWS_TO_STOP; $row++) {
            if (Normalizer::text($sheet->getCell($keyColumn.$row)->getValue()) === null) {
                $empty++;

                continue;
            }
            $empty = 0;
            yield $row;
        }
    }

    /** @return \Generator<int, string> valores no vacíos de una lista de catálogo */
    private function column(Worksheet $sheet, array $cols, string $header): \Generator
    {
        $letter = $cols[Normalizer::key($header)] ?? null;
        if (! $letter) {
            $this->report->warn('Tablas', "No se encontró la lista \"{$header}\".");

            return;
        }
        foreach ($this->rows($sheet, $letter) as $row) {
            yield $row => Normalizer::text($sheet->getCell($letter.$row)->getValue());
        }
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (is_numeric($value)) {
            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value))->startOfDay();
        }
        $text = Normalizer::text($value);
        if ($text === null) {
            return null;
        }
        try {
            return CarbonImmutable::parse(str_replace('/', '-', $text))->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function state(mixed $value, string $where): ?State
    {
        $raw = Normalizer::text($value);
        $name = Normalizer::state($raw);
        if ($name === null) {
            return null;
        }
        if (Normalizer::key($raw) !== Normalizer::key($name)) {
            $this->fixOnce($where, "Estado \"{$raw}\" → \"{$name}\"");
        }

        return $this->states[$name] ??= State::firstOrCreate(['name' => $name]);
    }

    private function faultType(mixed $value): ?FaultType
    {
        $name = Normalizer::text($value);
        if ($name === null) {
            return null;
        }

        return $this->faultTypes[Normalizer::key($name)] ??= FaultType::firstOrCreate(['name' => $name]);
    }

    private function part(mixed $value, string $where): ?Part
    {
        $raw = Normalizer::text($value);
        $name = Normalizer::part($raw);
        if ($name === null) {
            return null;
        }
        $key = Normalizer::key($name);
        if ($raw !== $name) {
            $this->fixOnce($where, "Repuesto \"{$raw}\" → \"{$name}\"");
        }

        return $this->parts[$key] ??= Part::firstOrCreate(['name' => $name]);
    }

    private function condition(mixed $value, string $where): ?AtmCondition
    {
        $name = Normalizer::text($value);
        if ($name === null) {
            $this->report->warn($where, 'Sin "Estatus Final"; el ticket queda pendiente por validación.');

            return null;
        }
        $key = Normalizer::key($name);
        if (! isset($this->conditions[$key])) {
            $this->report->warn($where, "Estatus final nuevo \"{$name}\": se creó y lleva el ticket a \"Pendiente por validación\". Revíselo en el catálogo.");
            $this->conditions[$key] = AtmCondition::create([
                'name' => $name,
                'resulting_ticket_status' => TicketStatus::PendingValidation,
                'sort' => 99,
            ]);
        }

        return $this->conditions[$key];
    }

    private function technician(mixed $value, string $where): ?User
    {
        $name = Normalizer::text($value);
        if ($name === null) {
            $this->report->warn($where, 'Sin técnico asignado.');

            return null;
        }
        $key = Normalizer::key($name);
        if (isset($this->technicians[$key])) {
            return $this->technicians[$key];
        }

        // Correo provisional: debe actualizarse en el maestro de técnicos antes de dar acceso.
        $email = Str::slug($name, '.').'@tecnicos.servipos.test';
        $user = User::firstOrCreate(['email' => $email], [
            'name' => $name,
            'role' => Role::Technician,
            'password' => Str::password(32),
        ]);
        if ($user->wasRecentlyCreated) {
            $this->report->count('Técnicos creados (con correo provisional)');
        }

        return $this->technicians[$key] = $user;
    }
}
