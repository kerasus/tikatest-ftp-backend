<?php

namespace App\Enums;

enum InventoryType: string
{
    case FabricCutter = 'fabric_cutter';
    case ColoringWorker = 'coloring_worker';
    case MoldingWorker = 'molding_worker';
    case Assembler = 'assembler';
    case CentralWarehouse = 'central_warehouse';

    /**
     * Get the Persian label for the inventory type.
     */
    public function label(): string
    {
        return match ($this) {
            self::FabricCutter => 'انبار برشکاری',
            self::ColoringWorker => 'انبار رنگکاری',
            self::MoldingWorker => 'انبار اتوکاری',
            self::Assembler => 'انبار مونتاژ کاری',
            self::CentralWarehouse => 'انبار مرکزی',
        };
    }

    /**
     * Get all inventory type values for validation.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
