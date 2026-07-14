<?php

namespace App\Enums;

enum RequiredItemType: string
{
    case ProductPart = 'App\Models\ProductPart';
    case RawMaterial = 'App\Models\RawMaterial';

    /**
     * Get the label for the type.
     */
    public function label(): string
    {
        return match ($this) {
            self::ProductPart => 'زیر محصول',
            self::RawMaterial => 'مواد اولیه',
        };
    }
}
