<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupTempUploads extends Command
{
    protected $signature = 'uploads:cleanup';
    protected $description = 'پاکسازی فایل‌های موقت قدیمی (بیش از ۲ ساعت)';

    public function handle()
    {
        $this->info('شروع پاکسازی فایل‌های موقت...');

        $disk = Storage::disk('local');

        if (!$disk->exists('temp_uploads')) {
            $this->info('پوشه temp_uploads وجود ندارد.');
            return;
        }

        $directories = $disk->directories('temp_uploads');

        $count = 0;
        $now = now();

        foreach ($directories as $dir) {
            // دریافت زمان تغییر آخرین فایل در پوشه
            // اگر پوشه خالی باشد، زمان ساخت پوشه را می‌گیریم
            $files = $disk->files($dir);

            if (empty($files)) {
                $timestamp = $disk->lastModified($dir);
            } else {
                $timestamp = 0;

                foreach ($files as $file) {
                    $fileTime = $disk->lastModified($file);
                    if ($fileTime > $timestamp) {
                        $timestamp = $fileTime;
                    }
                }
            }

            // محاسبه اختلاف زمانی
            $diffInMinutes = $now->diffInMinutes(\Carbon\Carbon::createFromTimestamp($timestamp));

            // اگر بیشتر از ۱۲۰ دقیقه (۲ ساعت) از آخرین تغییر گذشته است
            if ($diffInMinutes > 120) {
                $disk->deleteDirectory($dir);
                $count++;
                $this->line("پوشه {$dir} پاک شد.");
            }
        }

        $this->info("پاکسازی پایان یافت. {$count} پوشه حذف شد.");
    }
}
