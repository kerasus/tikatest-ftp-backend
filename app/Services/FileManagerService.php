<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\StorageAttributes;

class FileManagerService
{
    protected $ftpDisk;
    protected FileNameService $fileNameService;

    // کش ۲۴ ساعته برای ساختار کلی درخت و ۱۲ ساعته برای لیست فایل‌های هر پوشه
    public const TREE_CACHE_TTL = 86400; // ۲۴ ساعت
    public const LIST_CACHE_TTL = 43200; // ۱۲ ساعت

    public function __construct(FileNameService $fileNameService)
    {
        $this->ftpDisk = Storage::disk('remote_ftp');
        $this->fileNameService = $fileNameService;
    }

    /**
     * کلید واحد کش لیست فایل‌ها
     */
    public static function getListCacheKey(string $path): string
    {
        return 'ftp_files_' . md5('/' . trim($path, '/'));
    }

    public static function getTreeCacheKey(): string
    {
        return 'ftp_directory_tree_root';
    }

    /**
     * دریافت لیست فایل‌ها و پوشه‌های یک مسیر خاص
     */
    public function listFiles(string $path, bool $forceRefresh = false): array
    {
        $path = '/' . trim($path, '/');
        $cacheKey = self::getListCacheKey($path);

        if (!$forceRefresh && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $items = $this->safeListContents($path);

            $directories = [];
            $files = [];

            foreach ($items as $item) {
                $isDir = is_array($item)
                    ? (($item['type'] ?? '') === 'dir')
                    : ($item instanceof StorageAttributes && $item->isDir());

                $itemPath = is_array($item) ? ($item['path'] ?? '') : $item->path();
                $name = basename($itemPath);

                if ($isDir) {
                    $directories[] = [
                        'name' => $name,
                        'path' => '/' . ltrim($itemPath, '/'),
                        'type' => 'directory',
                    ];
                } else {
                    $size = is_array($item) ? ($item['size'] ?? null) : ($item->fileSize() ?? null);
                    $timestamp = is_array($item) ? ($item['timestamp'] ?? null) : ($item->lastModified() ?? null);

                    $files[] = [
                        'name' => $name,
                        'path' => '/' . ltrim($itemPath, '/'),
                        'size' => $size,
                        'timestamp' => $timestamp,
                        'type' => 'file',
                    ];
                }
            }

            $result = [
                'current_path' => $path,
                'directories' => $directories,
                'files' => $files,
            ];

            Cache::put($cacheKey, $result, self::LIST_CACHE_TTL);

            return $result;

        } catch (\Throwable $e) {
            Log::error('FTP listFiles failed: ' . $e->getMessage(), ['path' => $path]);

            return [
                'current_path' => $path,
                'directories' => [],
                'files' => [],
            ];
        }
    }

    /**
     * دریافت ساختار درختی دایرکتوری‌ها
     */
    public function getDirectoryTree(bool $forceRefresh = false): array
    {
        $cacheKey = self::getTreeCacheKey();

        if (!$forceRefresh && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $tree = [];
            $seasonItems = $this->safeListContents('/');

            foreach ($seasonItems as $seasonItem) {
                $seasonPath = is_array($seasonItem) ? $seasonItem['path'] : $seasonItem->path();
                $isDir = is_array($seasonItem) ? (($seasonItem['type'] ?? '') === 'dir') : $seasonItem->isDir();

                if (!$isDir) {
                    continue;
                }

                $seasonName = basename($seasonPath);
                if (!isset($tree[$seasonName])) {
                    $tree[$seasonName] = [];
                }

                $lessonItems = $this->safeListContents($seasonPath);

                foreach ($lessonItems as $lessonItem) {
                    $lessonPath = is_array($lessonItem) ? $lessonItem['path'] : $lessonItem->path();
                    $isLessonDir = is_array($lessonItem) ? (($lessonItem['type'] ?? '') === 'dir') : $lessonItem->isDir();

                    if (!$isLessonDir) {
                        continue;
                    }

                    $lessonName = basename($lessonPath);
                    if (!isset($tree[$seasonName][$lessonName])) {
                        $tree[$seasonName][$lessonName] = [];
                    }

                    $gradeItems = $this->safeListContents($lessonPath);

                    foreach ($gradeItems as $gradeItem) {
                        $gradePath = is_array($gradeItem) ? $gradeItem['path'] : $gradeItem->path();
                        $isGradeDir = is_array($gradeItem) ? (($gradeItem['type'] ?? '') === 'dir') : $gradeItem->isDir();

                        if (!$isGradeDir) {
                            continue;
                        }

                        $tree[$seasonName][$lessonName][] = basename($gradePath);
                    }

                    $tree[$seasonName][$lessonName] = array_values(array_unique($tree[$seasonName][$lessonName]));
                    sort($tree[$seasonName][$lessonName]);
                }

                ksort($tree[$seasonName]);
            }

            ksort($tree);

            Cache::put($cacheKey, $tree, self::TREE_CACHE_TTL);

            return $tree;

        } catch (\Throwable $e) {
            Log::error('FTP getDirectoryTree failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * متد امن خواندن محتویات بدون تاخیر روی پوشه‌های خالی و ضد باگ هاست پارس‌پک
     */
    private function safeListContents(string $path, int $maxAttempts = 2): array
    {
        $path = '/' . trim($path, '/');
        if ($path === '//') {
            $path = '/';
        }

        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;

            try {
                $disk = ($attempt > 1) ? Storage::disk('remote_ftp') : $this->ftpDisk;
                $contents = $disk->listContents($path);
                $items = is_array($contents) ? $contents : iterator_to_array($contents);

                if (!empty($items)) {
                    return $items;
                }

                if ($attempt < $maxAttempts) {
                    usleep(100000); // 100ms وقفه فقط برای باگ‌های رندوم سوکت
                    continue;
                }

                return [];

            } catch (\Throwable $e) {
                if ($attempt >= $maxAttempts) {
                    Log::warning("FTP safeListContents network error", [
                        'path' => $path,
                        'error' => $e->getMessage()
                    ]);
                    return [];
                }
                usleep(150000);
            }
        }

        return [];
    }

    /**
     * تغییر نام فایل
     */
    public function rename(string $oldPath, string $newFilename): string
    {
        $directory = dirname($oldPath);
        $normalized = $this->fileNameService->normalize($newFilename, $directory, $this->ftpDisk);
        $newPath = trim($directory, '/') . '/' . $normalized;

        $success = $this->ftpDisk->move($oldPath, $newPath);

        if (!$success) {
            throw new Exception('خطا در تغییر نام فایل');
        }

        // فلاش کردن کش دایرکتوری
        $this->flushDirectoryCaches($directory);

        return $newPath;
    }

    /**
     * حذف فایل
     */
    public function delete(string $path): bool
    {
        $directory = dirname($path);
        $result = $this->ftpDisk->delete($path);

        if ($result) {
            // فلاش کردن کش دایرکتوری
            $this->flushDirectoryCaches($directory);
        }

        return $result;
    }

    /**
     * جابجایی فایل بین پوشه‌ها
     */
    public function move(string $oldPath, string $destinationDirectory): string
    {
        $oldPath = '/' . trim($oldPath, '/');
        $destinationDirectory = '/' . trim($destinationDirectory, '/');

        if (!$this->ftpDisk->directoryExists($destinationDirectory)) {
            throw new Exception('پوشه مقصد وجود ندارد.');
        }

        if (!$this->ftpDisk->fileExists($oldPath)) {
            throw new Exception('فایل مبدأ وجود ندارد.');
        }

        $filename = basename($oldPath);
        $normalizedFilename = $this->fileNameService->normalize($filename, $destinationDirectory, $this->ftpDisk);
        $newPath = trim($destinationDirectory, '/') . '/' . $normalizedFilename;

        $success = $this->ftpDisk->move(ltrim($oldPath, '/'), ltrim($newPath, '/'));

        if (!$success) {
            throw new Exception('انتقال فایل در FTP ناموفق بود.');
        }

        // فلاش کردن کش پوشه مبدأ و مقصد
        $this->flushDirectoryCaches(dirname($oldPath));
        $this->flushDirectoryCaches($destinationDirectory);

        return '/' . ltrim($newPath, '/');
    }

    /**
     * فلاش کردن تمام کش‌های مرتبط با یک دایرکتوری (هم FileManager هم SanjadSync)
     */
    public function flushDirectoryCaches(string $directory): void
    {
        $dir = '/' . trim($directory, '/');

        // ۱. پاکسازی کش لیست این پوشه
        Cache::forget(self::getListCacheKey($dir));

        // ۲. پاکسازی کش سینک سانجاد
        if (class_exists(SanjadSyncService::class) && method_exists(SanjadSyncService::class, 'getFtpCacheKey')) {
            Cache::forget(SanjadSyncService::getFtpCacheKey($dir));
        }

        // اگر پوشه‌ای ساخته یا حذف شده باشد، درخت را هم باطل می‌کنیم
        Cache::forget(self::getTreeCacheKey());
    }
}
