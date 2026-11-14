<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\Export;

class ParkingReportExport implements Export, WithMultipleSheets
{
    public function __construct(private array $report) {}

    public function sheets(): array
    {
        $summary = ['title' => 'Resumen', 'columns' => [
            ['key' => 'label', 'label' => 'Indicador', 'format' => 'text'], ['key' => 'value', 'label' => 'Valor', 'format' => 'dynamic'],
        ], 'rows' => $this->report['summary']];

        return array_map(fn ($table) => new ParkingReportSheet($this->report, $table), [$summary, ...$this->report['tables']]);
    }
}
