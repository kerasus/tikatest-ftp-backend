<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class UserProductionSummaryExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithColumnWidths
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
            'کاربر',
            'زیر محصول',
            'رنگ',
            'پارچه',
            'جمع دسته',
            'جمع کل',
            'تجهیزات',
            'کد تجهیزات',
            'علت توقف',
            'مدت توقف',
        ];
    }

    public function map($row): array
    {
        return [
            ($row->firstname ?? '') . ' ' . ($row->lastname ?? '') . ' (' . ($row->employee_code ?? '-') . ')',
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
            'A' => 25, // کاربر
            'B' => 30, // زیر محصول
            'C' => 20, // رنگ
            'D' => 20, // پارچه
            'E' => 15, // جمع دسته
            'F' => 15, // جمع کل
            'G' => 25, // تجهیزات
            'H' => 20, // کد تجهیزات
            'I' => 25, // علت توقف
            'J' => 15, // مدت توقف
        ];
    }
}
