<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Traits\HasPermissions;

class SanjadClass extends Model
{
    use HasPermissions;

    protected string $guard_name = 'web';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'sanjad_class_id',
        'name',
        'academic_level_name',
        'academic_field_name',
        'is_deleted_at_source',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'sanjad_class_id' => 'integer',
        'is_deleted_at_source' => 'boolean',
    ];

    /**
     * Scope a query to only include classes that are not deleted at the source (Sanjad).
     *
     * استفاده: SanjadClass::active()->get()
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_deleted_at_source', false);
    }
}
