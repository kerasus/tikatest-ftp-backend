<?php

namespace App\Services;

use App\Models\FileActivityLog;
use App\Enums\FileActivityStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class FileActivityLogger
{
    /**
     * ثبت لاگ عملیات فایل
     *
     * @param string $action
     * @param FileActivityStatus $status
     * @param array $data
     * @return FileActivityLog|null
     */
    public function log(
        string $action,
        FileActivityStatus $status = FileActivityStatus::SUCCESS,
        array $data = []
    ): ?FileActivityLog {
        try {
            $meta = $data['meta'] ?? null;
            if (is_array($meta)) {
                $meta = json_encode($meta, JSON_UNESCAPED_UNICODE);
            }
            return FileActivityLog::create([
                'user_id'          => $data['user_id'] ?? Auth::id(),
                'action'           => $action,
                'status'           => $status->value,
                'path'             => $data['path'] ?? null,
                'source_path'      => $data['source_path'] ?? null,
                'destination_path' => $data['destination_path'] ?? null,
                'file_name'        => $data['file_name'] ?? null,
                'file_size'        => $data['file_size'] ?? null,
                'upload_id'        => $data['upload_id'] ?? null,
                'error_message'    => $data['error_message'] ?? null,
                'meta'             => $meta,
                'ip_address'       => request()?->ip(),
                'user_agent'       => request()?->userAgent(),
            ]);
        } catch (\Throwable $e) {
            // در صورت خطا در ثبت دیتابیس، خطا در لاگ سیستم ثبت می‌شود تا فرآیند اصلی کاربر قطع نشود
            Log::error('Failed to write FileActivityLog: ' . $e->getMessage(), [
                'action' => $action,
                'data'   => $data,
            ]);
            return null;
        }
    }
}
