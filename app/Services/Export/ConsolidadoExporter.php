<?php

namespace App\Services\Export;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\Visit;
use App\Services\Reports\TableExporter;
use Carbon\CarbonInterface;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Table\TableStyle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Genera el .xlsx con la misma estructura de la pestaña "Base General" del
 * consolidado actual (RF-4.3): una fila por visita, desde la columna B y con
 * encabezados en la fila 2. Si una visita usó varios repuestos, van juntos en
 * la misma celda ("PICKER x2, FEEDSHAFT x1") y la cantidad es el total.
 *
 * La segunda hoja, "Pendientes", trae los tickets sin cerrar al momento de
 * descargar (asignados, en camino, por repuesto, por validar...), sin importar
 * las fechas: así el reporte diario ya no necesita una captura aparte.
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

    /** Hoja "Pendientes": encabezado => ancho de columna. */
    private const PENDING_COLUMNS = [
        'N°' => 6.0,
        'N° Ticket' => 16.0,
        'Fecha de Reporte' => 16.5,
        'N° ATM´s' => 13.7,
        'Serial de Equipo' => 21.6,
        'Estado' => 17.6,
        'Nombre de Agencia' => 29.9,
        'Reporte de Falla' => 19.1,
        'Estatus' => 26.0,
        'Tecnico' => 22.4,
        'Última Atención' => 12.0,
        'Trabajo realizado/Observacion' => 60.0,
        'Días Abierto' => 10.0,
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
        $book->getProperties()->setCreator(TableExporter::COMPANY)->setCompany(TableExporter::COMPANY)
            ->setTitle('Libro Consolidado Relación de Atenciones');
        $sheet = $book->getActiveSheet()->setTitle('Base General');
        $sheet->getColumnDimension('A')->setWidth(1.6);

        // Fila 1 (vacía en el libro original): logo y título. La tabla sigue en B2, igual que antes.
        $sheet->getRowDimension(1)->setRowHeight(46);
        app(TableExporter::class)->logo($sheet, 'B1', 52);
        $sheet->setCellValue('E1', 'Libro Consolidado Relación de Atenciones');
        $sheet->getStyle('E1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('00194C');
        $sheet->setCellValue('I1', sprintf('%s · Del %s al %s · Generado el %s', TableExporter::COMPANY, $from->format('d/m/Y'), $to->format('d/m/Y'), now()->format('d/m/Y h:i a')));
        $sheet->getStyle('I1')->getFont()->setSize(9)->getColor()->setRGB('687799');
        $sheet->getStyle('B1:R1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

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

            TableExporter::writeRow($sheet, [
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
            ], $row, firstColumn: 2);
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

        $this->pendingSheet($book->createSheet(), $bankId);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    /** Tickets sin cerrar en este momento, del más antiguo al más reciente. */
    private function pendingSheet(Worksheet $sheet, ?int $bankId): void
    {
        $tickets = Ticket::query()
            ->with(['atm.state', 'faultType', 'technician', 'visits'])
            ->whereNotIn('status', [TicketStatus::Closed, TicketStatus::Cancelled])
            ->when($bankId, fn ($q) => $q->where('bank_id', $bankId))
            ->orderBy('opened_at')
            ->orderBy('id')
            ->get();

        $sheet->setTitle('Pendientes');
        $sheet->getColumnDimension('A')->setWidth(1.6);
        $sheet->getRowDimension(1)->setRowHeight(46);
        app(TableExporter::class)->logo($sheet, 'B1', 52);
        $sheet->setCellValue('E1', 'Tickets pendientes al '.now()->format('d/m/Y h:i a'));
        $sheet->getStyle('E1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('00194C');
        $sheet->setCellValue('I1', sprintf('%s · Asignados, en camino, por repuesto, por validar y por confirmar · %d ticket%s', TableExporter::COMPANY, $tickets->count(), $tickets->count() === 1 ? '' : 's'));
        $sheet->getStyle('I1')->getFont()->setSize(9)->getColor()->setRGB('687799');
        $sheet->getStyle('B1:N1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        $col = 'B';
        foreach (self::PENDING_COLUMNS as $header => $width) {
            $sheet->setCellValue("{$col}2", $header);
            $sheet->getColumnDimension($col)->setWidth($width);
            $col++;
        }
        $lastCol = chr(ord('B') + count(self::PENDING_COLUMNS) - 1);
        $sheet->getRowDimension(2)->setRowHeight(36);
        $sheet->getStyle("B2:{$lastCol}2")->getAlignment()
            ->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $row = 3;
        foreach ($tickets as $i => $ticket) {
            $atm = $ticket->atm;
            $last = $ticket->visits->last();

            TableExporter::writeRow($sheet, [
                $i + 1,
                $ticket->number,
                $ticket->opened_at ? ExcelDate::PHPToExcel($ticket->opened_at) : null,
                $atm?->atm_number,
                $atm?->serial,
                $atm?->state?->name,
                $atm?->agency_name,
                $ticket->faultType?->name,
                $ticket->status->label(),
                $ticket->technician?->name ?? 'Sin asignar',
                $last ? ExcelDate::PHPToExcel($last->visit_date) : null,
                $last?->work_done ?? $ticket->description,
                $ticket->opened_at ? (int) $ticket->opened_at->diffInDays(now()) : null,
            ], $row, firstColumn: 2);
            $row++;
        }

        $lastRow = max(3, $row - 1);
        $sheet->getStyle("D3:D{$lastRow}")->getNumberFormat()->setFormatCode('dd/mm/yyyy h:mm AM/PM');
        $sheet->getStyle("L3:L{$lastRow}")->getNumberFormat()->setFormatCode('d-mmm');
        $sheet->getStyle("M3:M{$lastRow}")->getAlignment()->setWrapText(true);

        $table = new Table("B2:{$lastCol}{$lastRow}", 'tab_pend');
        $table->setStyle((new TableStyle)->setTheme(TableStyle::TABLE_STYLE_MEDIUM11)->setShowRowStripes(true));
        $sheet->addTable($table);
        $sheet->freezePane('D3');
    }

    public function save(Spreadsheet $book, string $path): void
    {
        (new Xlsx($book))->save($path);
    }
}
