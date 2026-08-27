<?php

namespace App\Enums;

enum FileActivityStatus: string
{
    case SUCCESS = 'success';
    case FAILED = 'failed';
    case PENDING = 'pending'; // در صورت نیاز برای عملیات‌های طولانی/ناقص
}
