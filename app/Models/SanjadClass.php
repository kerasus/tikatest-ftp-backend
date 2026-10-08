<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Traits\HasPermissions;

class SanjadClass extends Model
{
    use HasPermissions;

    protected string $guard_name = 'web';

    protected $fillable = [
        'sanjad_class_id',
        'name',
    ];

    // نیازی به تعریف دستی permissions() نیست.
    // trait HasPermissions خودش این رابطه را به صورت morphToMany
    // روی جدول model_has_permissions تعریف می‌کند.
}
