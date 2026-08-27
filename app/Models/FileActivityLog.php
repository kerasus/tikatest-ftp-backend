<?php

namespace App\Models;

use App\Enums\FileActivityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FileActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'status',
        'path',
        'source_path',
        'destination_path',
        'file_name',
        'file_size',
        'upload_id',
        'error_message',
        'meta',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'status'    => FileActivityStatus::class,
            'meta' => 'array',
            'file_size' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    public function scopeDateFrom(Builder $query, string $date): Builder
    {
        return $query->whereDate('created_at', '>=', $date);
    }

    public function scopeDateTo(Builder $query, string $date): Builder
    {
        return $query->whereDate('created_at', '<=', $date);
    }
}

