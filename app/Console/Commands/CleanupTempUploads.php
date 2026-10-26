<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class CleanupTempUploads extends Command
{
    protected $signature = 'uploads:cleanup';
    protected $description = 'پاکسازی فایل‌های موقت قدیمی (بیش از ۲ ساعت)';

    public function handle()
    {
        $this->info('شروع پاکسازی فایل‌های موقت...');
//        Log::info('[UploadsCleanup] شروع اجرای دستور پاکسازی فایل‌های موقت.');

        $disk = Storage::disk('local');

        if (!$disk->exists('temp_uploads')) {
            $msg = 'پوشه temp_uploads وجود ندارد.';
            $this->info($msg);
//            Log::info("[UploadsCleanup] {$msg}");
            return;
        }

        $directories = $disk->directories('temp_uploads');
        $totalDirectories = count($directories);

        $count = 0;
        $now = now();

        // پاکسازی فایل‌های lock مستقیم در ریشه temp_uploads
        $rootFiles = $disk->files('temp_uploads');
        foreach ($rootFiles as $rootFile) {
            if (str_ends_with($rootFile, '.lock')) {
                $fileTime = $disk->lastModified($rootFile);
                if ($now->diffInMinutes(Carbon::createFromTimestamp($fileTime)) > 120) {
                    $disk->delete($rootFile);
//                    Log::info("[UploadsCleanup] فایل قفل {$rootFile} حذف شد.");
                }
            }
        }

        foreach ($directories as $dir) {
            // دریافت زمان تغییر آخرین فایل در پوشه
            // اگر پوشه خالی باشد، زمان ساخت پوشه را می‌گیریم
            $files = $disk->allFiles($dir);

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
            $diffInMinutes = $now->diffInMinutes(Carbon::createFromTimestamp($timestamp));

            // اگر بیشتر از ۱۲۰ دقیقه (۲ ساعت) از آخرین تغییر گذشته است
            if ($diffInMinutes > 120) {
                $disk->deleteDirectory($dir);
                $count++;
                $this->line("پوشه {$dir} پاک شد.");
//                Log::info("[UploadsCleanup] پوشه {$dir} به علت گذشت {$diffInMinutes} دقیقه حذف شد.");
            }
        }

        $summary = "پاکسازی پایان یافت. از مجموع {$totalDirectories} پوشه، {$count} پوشه قدیمی حذف شد.";
        $this->info($summary);
//        Log::info("[UploadsCleanup] {$summary}");
    }
}
