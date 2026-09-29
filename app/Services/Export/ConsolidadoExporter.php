<?php

namespace App\Services\Export;

use App\Models\Visit;
use Carbon\CarbonInterface;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Table\TableStyle;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Genera el .xlsx con la misma estructura de la pestaña "Base General" del
 * consolidado actual (RF-4.3): una fila por visita, desde la columna B y con
 * encabezados en la fila 2. Si una visita usó varios repuestos, van juntos en
 * la misma celda ("PICKER x2, FEEDSHAFT x1") y la cantidad es el total.
 */
class ConsolidadoExporter
{
    /** Encabezado => ancho de columna, tal como en el libro original. */
    private const COLUMNS = [
        'N°' => 10.1,
        'Fecha de Reporte' => 10.6,
        'N° ATM´s' => 13.7,
        'Serial de Equipo' => 21.6,
        'Código de Agencia' => 22.6,
        'Estado' => 17.6,
        'Nombre de Agencia' => 29.9,
        'N° Constancia' => 15.9,
        'Reporte de Falla' => 19.1,
        'Cambio de Repuesto' => 14.9,
        'Repuesto Utilizado' => 20.7,
        'Cantidad de Repuestos' => 17.1,
        'Trabajo realizado/Observacion' => 67.4,
        'Estatus Final' => 40.3,
        'Tecnico' => 22.4,
        'Mes' => 12.1,
        'N° Ticket' => 16.0,
    ];

    public function build(CarbonInterface $from, CarbonInterface $to, ?int $bankId = null): Spreadsheet
    {
        $visits = Visit::query()
            ->with(['ticket.atm.state', 'faultType', 'atmCondition', 'technician', 'parts'])
            ->whereDate('visit_date', '>=', $from->toDateString())
            ->whereDate('visit_date', '<=', $to->toDateString())
            ->when($bankId, fn ($q) => $q->whereHas('ticket', fn ($t) => $t->where('bank_id', $bankId)))
            ->orderBy('visit_date')
            ->orderBy('id')
            ->get();

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Base General');
        $sheet->getColumnDimension('A')->setWidth(1.6);

        $col = 'B';
        foreach (self::COLUMNS as $header => $width) {
            $sheet->setCellValue("{$col}2", $header);
            $sheet->getColumnDimension($col)->setWidth($width);
            $col++;
        }
        $lastCol = chr(ord('B') + count(self::COLUMNS) - 1);
        $sheet->getRowDimension(2)->setRowHeight(48);
        $sheet->getStyle("B2:{$lastCol}2")->getAlignment()
            ->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $row = 3;
        foreach ($visits as $i => $visit) {
            $atm = $visit->ticket->atm;
            $parts = $visit->parts;
            $constancia = $visit->constancia_number;

            $sheet->fromArray([
                $i + 1,
                ExcelDate::PHPToExcel($visit->visit_date),
                $atm->atm_number,
                $atm->serial,
                is_numeric($atm->agency_code) ? (int) $atm->agency_code : $atm->agency_code,
                $atm->state?->name,
                $atm->agency_name,
                is_numeric($constancia) ? (int) $constancia : $constancia,
                $visit->faultType?->name,
                $visit->parts_changed ? 'Si' : 'No',
                match ($parts->count()) {
                    0 => null,
                    1 => $parts->first()->name,
                    default => $visit->partsSummary(),
                },
                $parts->count() ? (int) $parts->sum('pivot.quantity') : null,
                $visit->work_done,
                $visit->atmCondition?->name,
                $visit->technician?->name,
                ExcelDate::PHPToExcel($visit->visit_date),
                $visit->ticket->number,
            ], null, "B{$row}", true);
            $row++;
        }

        $lastRow = max(3, $row - 1);
        $sheet->getStyle("C3:C{$lastRow}")->getNumberFormat()->setFormatCode('d-mmm');
        $sheet->getStyle("Q3:Q{$lastRow}")->getNumberFormat()->setFormatCode('mmmm');
        $sheet->getStyle("O3:O{$lastRow}")->getAlignment()->setWrapText(true);

        $table = new Table("B2:{$lastCol}{$lastRow}", 'tab_gen');
        $table->setStyle((new TableStyle)->setTheme(TableStyle::TABLE_STYLE_MEDIUM11)->setShowRowStripes(true));
        $sheet->addTable($table);
        $sheet->freezePane('C3');

        return $book;
    }

    public function save(Spreadsheet $book, string $path): void
    {
        (new Xlsx($book))->save($path);
    }
}
