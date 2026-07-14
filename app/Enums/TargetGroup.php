<?php

namespace App\Enums;

enum TargetGroup: string
{
    case Resident = 'resident';
    case Owner = 'owner';

    /**
     * Get the label for the target group.
     */
    public function label(): string
    {
        return match ($this) {
            self::Resident => 'ساکنین',
            self::Owner => 'مالکین',
        };
    }
}
