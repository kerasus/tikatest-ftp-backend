<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProductionSummaryExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithColumnWidths
{
    protected Collection $data;

    public function __construct(Collection $data)
    {
        $this->data = $data;
    }

    public function collection(): Collection
    {
        return $this->data;
    }

    public function headings(): array
    {
        return [
            'زیر محصول',
            'رنگ',
            'پارچه',
            'جمع دسته',
            'جمع کل گلبرگ',
            'تجهیزات',
            'کد تجهیزات',
            'علت توقف',
            'مدت توقف',
        ];
    }

    public function map($row): array
    {
        return [
            $row->product_part_name,
            $row->color_name ?? 'نامشخص',
            $row->fabric_name ?? 'نامشخص',
            $row->total_bunch,
            $row->total_petals,
            $row->equipment ?? 'نامشخص',
            $row->equipment_code ?? 'نامشخص',
            $row->downtime_reason_title ?? 'نامشخص',
            $row->downtime_duration ?? 0,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            // هدر
            1 => [
                'font' => ['bold' => true],
                'alignment' => ['horizontal' => 'center']
            ]
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 30, // زیر محصول
            'B' => 20, // رنگ
            'C' => 20, // پارچه
            'D' => 15, // جمع دسته
            'E' => 20, // جمع کل گلبرگ
            'F' => 25, // تجهیزات
            'G' => 20, // کد تجهیزات
            'H' => 25, // علت توقف
            'I' => 15, // مدت توقف
        ];
    }
}
