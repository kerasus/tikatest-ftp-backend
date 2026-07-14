<?php

namespace App\Enums;

enum TransferStatusType: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * Get the label for the type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'در انتظار تایید',
            self::Approved => 'تایید شده',
            self::Rejected => 'لغو شده',
        };
    }
}
