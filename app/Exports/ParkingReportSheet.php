<?php

namespace App\Exports;

use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\{FromArray, WithCustomValueBinder, WithStrictNullComparison, WithStyles, WithTitle};
use PhpOffice\PhpSpreadsheet\Cell\{Cell, Coordinate, DataType, DefaultValueBinder};
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\{PageSetup, Worksheet};

class ParkingReportSheet extends DefaultValueBinder implements FromArray, WithTitle, WithStyles, WithCustomValueBinder, WithStrictNullComparison
{
    private array $formats = [];

    public function __construct(private array $report, private array $table) {}

    public function title(): string
    {
        return mb_substr($this->table['title'], 0, 31);
    }

    public function array(): array
    {
        $rows = [['Sistema Web de Estacionamiento'], [$this->report['title'].' · '.$this->table['title']],
            [implode(' | ', array_map(fn ($key, $value) => $key.': '.$value, array_keys($this->report['filter_labels']), $this->report['filter_labels']))],
            ['Emisión (America/La_Paz)', Date::dateTimeToExcel(CarbonImmutable::parse($this->report['generated_at'], 'America/La_Paz'))], [''],
            array_column($this->table['columns'], 'label')];
        $this->formats['B4'] = 'dd/mm/yyyy hh:mm:ss';
        foreach ($this->table['rows'] as $row) {
            $cells = [];
            foreach ($this->table['columns'] as $index => $column) {
                $value = $row[$column['key']] ?? null;
                $format = $column['format'] === 'dynamic' ? $row['format'] : $column['format'];
                $address = Coordinate::stringFromColumnIndex($index + 1).(count($rows) + 1);
                if ($value !== null) {
                    if (in_array($format, ['money', 'number', 'percent'])) {
                        $value = (float) $value;
                        $this->formats[$address] = match ($format) { 'money' => '"Bs" #,##0.00;[Red]-"Bs" #,##0.00', 'percent' => '0.00"%"', default => '0.####' };
                    } elseif (in_array($format, ['date', 'datetime', 'month'])) {
                        $value = Date::dateTimeToExcel(CarbonImmutable::parse($value.($format === 'month' ? '-01' : ''), 'America/La_Paz'));
                        $this->formats[$address] = match ($format) { 'date' => 'dd/mm/yyyy', 'month' => 'yyyy-mm', default => 'dd/mm/yyyy hh:mm:ss' };
                    }
                }
                $cells[] = $value;
            }
            $rows[] = $cells;
        }
        if (! $this->table['rows']) $rows[] = ['Sin registros para estos filtros.'];

        return $rows;
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        // Plates/references beginning with '=' must remain text, never executable formulas.
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function styles(Worksheet $sheet): ?array
    {
        $lastColumn = Coordinate::stringFromColumnIndex(max(2, count($this->table['columns'])));
        foreach ([1, 2, 3] as $row) $sheet->mergeCells('A'.$row.':'.$lastColumn.$row);
        $sheet->getStyle('A3')->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(3)->setRowHeight(35);
        $sheet->freezePane('A7');
        if ($this->table['rows']) $sheet->setAutoFilter('A6:'.$lastColumn.(6 + count($this->table['rows'])));
        foreach ($this->formats as $cell => $format) $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($format);
        foreach (range(1, max(2, count($this->table['columns']))) as $index) $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setWidth($index === 1 ? 27 : 23);
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_LETTER)->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(1, 6);
        $sheet->getHeaderFooter()->setOddFooter('Sistema Web de Estacionamiento | Página &P de &N');

        return [1 => ['font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '206BC4']]], 2 => ['font' => ['bold' => true, 'size' => 12]],
            6 => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '206BC4']]]];
    }
}
