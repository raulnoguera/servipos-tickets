<?php

namespace App\Services\Reports;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Table\TableStyle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Exporta un reporte tabular a .xlsx con la imagen corporativa: logo,
 * título, filtros, autor y fecha; tabla con formato; pie con el total y
 * configuración de impresión (horizontal, ajustado al ancho, encabezados
 * repetidos y número de página).
 */
class TableExporter
{
    public const COMPANY = 'ServiPos 2019, C.A.';

    public const SYSTEM = 'Sistema de Monitoreo de Tickets ATM';

    private const NAVY = '00194C';

    private const BLUE = '0055FF';

    private const MUTED = '687799';

    private const LINE = 'E1E5EE';

    /** Fila donde empieza la tabla (encabezados de columna). */
    private const HEADER_ROW = 7;

    /** Columnas de texto largo: se muestran en varias líneas. */
    private const LONG_TEXT = ['work_done', 'parts', 'description', 'top_fault', 'agency_name'];

    /**
     * @param  array<string, string>  $columns  clave => encabezado
     * @param  iterable<array<string, mixed>>  $rows
     * @param  array<string, string>  $formats  clave => formato de número de Excel
     */
    public function download(string $title, string $filtersText, array $columns, iterable $rows, string $fileName, array $formats = []): BinaryFileResponse
    {
        $book = new Spreadsheet;
        $book->getProperties()
            ->setCreator(self::COMPANY)
            ->setCompany(self::COMPANY)
            ->setTitle($title)
            ->setSubject(self::SYSTEM)
            ->setDescription($filtersText);
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

        $sheet = $book->getActiveSheet()->setTitle(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', '', $title), 0, 31));
        $sheet->setShowGridlines(false);

        $keys = array_keys($columns);
        $lastCol = Coordinate::stringFromColumnIndex(max(count($keys), 6));
        $tableLastCol = Coordinate::stringFromColumnIndex(count($keys));

        $this->header($sheet, $title, $filtersText, $lastCol);

        // Encabezados de columna
        $h = self::HEADER_ROW;
        $sheet->fromArray(array_values($columns), null, "A{$h}");
        $sheet->getRowDimension($h)->setRowHeight(30);
        $sheet->getStyle("A{$h}:{$tableLastCol}{$h}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::NAVY]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::NAVY]]],
        ]);

        // Datos
        $r = $h + 1;
        $widths = array_map(fn ($label) => mb_strlen($label), array_values($columns));
        foreach ($rows as $row) {
            $values = array_map(fn ($k) => $row[$k] ?? null, $keys);
            self::writeRow($sheet, $values, $r);
            foreach ($values as $i => $value) {
                if (is_string($value)) {
                    $widths[$i] = max($widths[$i], min(60, mb_strlen($value)));
                }
            }
            $r++;
        }
        $lastRow = max($h + 1, $r - 1);
        $count = $r - $h - 1;

        foreach ($keys as $i => $key) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $long = in_array($key, self::LONG_TEXT, true);
            // Las fechas y tiempos no son texto: se reserva su ancho según el formato.
            $formatWidth = match (true) {
                ! isset($formats[$key]) => 0,
                str_contains($formats[$key], 'yyyy') => 17,
                default => 12,
            };
            $sheet->getColumnDimension($col)->setWidth($long ? min(55, max(18, $widths[$i] * 0.95)) : min(34, max(10, $widths[$i] + 3, $formatWidth)));
            if (isset($formats[$key])) {
                $sheet->getStyle("{$col}".($h + 1).":{$col}{$lastRow}")->getNumberFormat()->setFormatCode($formats[$key]);
                $sheet->getStyle("{$col}".($h + 1).":{$col}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            if ($long) {
                $sheet->getStyle("{$col}".($h + 1).":{$col}{$lastRow}")->getAlignment()->setWrapText(true);
            }
        }

        if ($count > 0) {
            $sheet->getStyle('A'.($h + 1).":{$tableLastCol}{$lastRow}")->applyFromArray([
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::LINE]]],
            ]);
            $table = new Table("A{$h}:{$tableLastCol}{$lastRow}", 'reporte');
            $table->setStyle((new TableStyle)->setTheme(TableStyle::TABLE_STYLE_LIGHT9)->setShowRowStripes(true));
            $sheet->addTable($table);
        } else {
            $sheet->setCellValue('A'.($h + 1), 'Sin registros para los filtros seleccionados.');
            $sheet->getStyle('A'.($h + 1))->getFont()->setItalic(true)->getColor()->setRGB(self::MUTED);
        }

        // Pie
        $foot = $lastRow + 2;
        $sheet->setCellValue("A{$foot}", 'Total de registros: '.number_format($count, 0, ',', '.'));
        $sheet->getStyle("A{$foot}")->getFont()->setBold(true)->getColor()->setRGB(self::NAVY);
        $sheet->setCellValue('A'.($foot + 1), self::COMPANY.' · '.self::SYSTEM.' · Documento generado automáticamente.');
        $sheet->getStyle('A'.($foot + 1))->getFont()->setSize(8)->getColor()->setRGB(self::MUTED);

        $sheet->freezePane('A'.($h + 1));
        $this->printSetup($sheet, $title, $h);

        $path = tempnam(sys_get_temp_dir(), 'reporte');
        (new Xlsx($book))->save($path);

        return response()->download($path, $fileName)->deleteFileAfterSend();
    }

    /**
     * Escribe una fila: los textos siempre como texto (nunca como fórmula), para
     * evitar la inyección de fórmulas (p. ej. un "trabajo realizado" que empiece
     * con "=HYPERLINK(...)" se ejecutaría al abrir el archivo).
     *
     * @param  list<mixed>  $values
     */
    public static function writeRow(Worksheet $sheet, array $values, int $row, int $firstColumn = 1): void
    {
        foreach ($values as $i => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $cell = Coordinate::stringFromColumnIndex($firstColumn + $i).$row;
            is_string($value)
                ? $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING)
                : $sheet->setCellValue($cell, $value);
        }
    }

    /**
     * Encabezado corporativo: logo, título, empresa, filtros y autor/fecha,
     * con una franja azul debajo. Ocupa las filas 1 a 5.
     */
    public function header(Worksheet $sheet, string $title, string $filtersText, string $lastCol): void
    {
        foreach ([1 => 22, 2 => 24, 3 => 16, 4 => 16, 5 => 6] as $row => $height) {
            $sheet->getRowDimension($row)->setRowHeight($height);
        }
        $this->logo($sheet, 'A1', 70);

        // El texto empieza a la derecha del logo (columna C).
        $sheet->setCellValue('C1', self::COMPANY.' · '.self::SYSTEM);
        $sheet->getStyle('C1')->getFont()->setBold(true)->setSize(9)->getColor()->setRGB(self::BLUE);
        $sheet->setCellValueExplicit('C2', $title, DataType::TYPE_STRING);
        $sheet->getStyle('C2')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB(self::NAVY);
        $sheet->setCellValueExplicit('C3', $filtersText, DataType::TYPE_STRING);
        $sheet->getStyle('C3')->getFont()->setSize(10)->getColor()->setRGB('3D4A66');
        $user = auth()->user()?->name;
        $sheet->setCellValueExplicit('C4', 'Generado el '.now()->format('d/m/Y \a \l\a\s h:i a').($user ? " por {$user}" : ''), DataType::TYPE_STRING);
        $sheet->getStyle('C4')->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB(self::MUTED);

        $sheet->getStyle("A5:{$lastCol}5")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::BLUE]],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BLUE]]],
        ]);
    }

    /** Logo corporativo anclado en una celda, con la altura indicada en píxeles. */
    public function logo(Worksheet $sheet, string $cell, int $height): void
    {
        $file = public_path('brand/logo.png');
        if (! is_file($file)) {
            return;
        }
        $logo = new Drawing;
        $logo->setName('ServiPos 2019');
        $logo->setDescription('Logo ServiPos 2019');
        $logo->setPath($file);
        $logo->setHeight($height);
        $logo->setCoordinates($cell);
        $logo->setOffsetX(4);
        $logo->setOffsetY(4);
        $logo->setWorksheet($sheet);
    }

    private function printSetup(Worksheet $sheet, string $title, int $headerRow): void
    {
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_LETTER)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);
        $sheet->getPageMargins()->setTop(0.5)->setBottom(0.6)->setLeft(0.4)->setRight(0.4);
        $sheet->getHeaderFooter()
            ->setOddFooter('&L&8'.self::COMPANY.' — '.$title.'&R&8Página &P de &N');
    }
}
