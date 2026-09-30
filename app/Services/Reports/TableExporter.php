<?php

namespace App\Services\Reports;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Table\TableStyle;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Exporta un reporte tabular a .xlsx con título, filtros y tabla con formato. */
class TableExporter
{
    /**
     * @param  array<string, string>  $columns  clave => encabezado
     * @param  iterable<array<string, mixed>>  $rows
     * @param  array<string, string>  $formats  clave => formato de número de Excel
     */
    public function download(string $title, string $filtersText, array $columns, iterable $rows, string $fileName, array $formats = []): BinaryFileResponse
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', '', $title), 0, 31));

        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('00194C');
        $sheet->setCellValue('A2', $filtersText);
        $sheet->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('687799');

        $keys = array_keys($columns);
        $sheet->fromArray(array_values($columns), null, 'A4');
        $r = 5;
        foreach ($rows as $row) {
            $sheet->fromArray(array_map(fn ($k) => $row[$k] ?? null, $keys), null, "A{$r}", true);
            $r++;
        }
        $lastCol = $sheet->getHighestColumn(4);
        $lastRow = max(5, $r - 1);

        foreach ($formats as $key => $format) {
            $col = Coordinate::stringFromColumnIndex(array_search($key, $keys, true) + 1);
            $sheet->getStyle("{$col}5:{$col}{$lastRow}")->getNumberFormat()->setFormatCode($format);
        }
        foreach (range(1, count($keys)) as $i) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
        $sheet->getStyle("A4:{$lastCol}4")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('00194C');
        $sheet->getStyle("A4:{$lastCol}4")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        if ($r > 5) {
            $table = new Table("A4:{$lastCol}{$lastRow}", 'reporte');
            $table->setStyle((new TableStyle)->setTheme(TableStyle::TABLE_STYLE_MEDIUM2)->setShowRowStripes(true));
            $sheet->addTable($table);
        }
        $sheet->freezePane('A5');

        $path = tempnam(sys_get_temp_dir(), 'reporte');
        (new Xlsx($book))->save($path);

        return response()->download($path, $fileName)->deleteFileAfterSend();
    }
}
