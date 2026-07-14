<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case Pending = 'pending';
    case Cancelled = 'cancelled';

    /**
     * Get the label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'پرداخت نشده',
            self::Paid => 'پرداخت شده',
            self::Pending => 'در حال بررسی',
            self::Cancelled => 'لغو شده',
        };
    }
}
