<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMIN = 'admin';
    case TEACHER = 'teacher';
    case SUPPORT = 'support';

    /**
     * Get the label for the type.
     */
    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'ادمین',
            self::TEACHER => 'دبیر',
            self::SUPPORT => 'پشتیبان'
        };
    }

    /**
     * Get all role names for validation.
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'value');
    }
}
