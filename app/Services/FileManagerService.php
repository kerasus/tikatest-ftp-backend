<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FileManagerService
{
    protected $ftpDisk;
    protected FileNameService $fileNameService;

    public function __construct(FileNameService $fileNameService)
    {
        // استفاده از دیسک FTP که قبلاً تنظیم کردیم
        $this->ftpDisk = $this->getStorageDisk();
        $this->fileNameService = $fileNameService;
    }

    private function getStorageDisk ()
    {
        return Storage::disk('remote_ftp');
//        return Storage::disk('sftp_remote');
    }

    /**
     * دریافت لیست فایل‌ها و پوشه‌های یک مسیر خاص
     * فیلتر کردن بر اساس دسترسی‌های کاربر
     */
    public function listFiles($path)
    {
        // ۱. نرمال‌سازی مسیر (حذف اسلش‌های اضافی)
        $path = '/' . trim($path, '/');

//        Log::info('FTP listFiles start', [
//            'path' => $path
//        ]);

        try {
            $items = [];
            $attempt = 0;
            $maxAttempts = 5;

            while ($attempt < $maxAttempts) {

                $attempt++;

                $ftpDisk = $this->getStorageDisk();

                $items = $ftpDisk->listContents($path)->toArray();

                if (!empty($items)) {
                    break;
                }

//                Log::warning('FTP empty result', [
//                    'path' => $path,
//                    'attempt' => $attempt
//                ]);

                usleep(1000000); // 1s
            }

            $directories = [];
            $files = [];
            $ftpDisk = $this->getStorageDisk();

            foreach ($items as $item) {

                $itemPath = $item['path'];
                $name = basename($itemPath);

                if ($item['type'] === 'dir') {

                    $directories[] = [
                        'name' => $name,
                        'path' => $itemPath,
                        'type' => 'directory'
                    ];

                } else {

                    $size = $item['size'] ?? null;
                    $timestamp = $item['timestamp'] ?? null;

                    // اگر FTP size را برنگرداند
                    if ($size === null) {
                        try {
                            $size = $ftpDisk->fileSize($itemPath);
                        } catch (\Throwable $e) {
                            $size = null;
                        }
                    }

                    // اگر timestamp خالی بود
                    if ($timestamp === null) {
                        try {
                            $timestamp = $ftpDisk->lastModified($itemPath);
                        } catch (\Throwable $e) {
                            $timestamp = null;
                        }
                    }


                    $files[] = [
                        'name' => $name,
                        'path' => $itemPath,
                        'size' => $size,
                        'timestamp' => $timestamp,
                        'type' => 'file'
                    ];
                }
            }

//            Log::info('FTP list result', [
//                'directories' => count($directories),
//                'files' => count($files)
//            ]);

            return [
                'current_path' => $path,
                'directories' => $directories,
                'files' => $files,
            ];

        } catch (\Throwable $e) {

//            Log::error('FTP listFiles failed', [
//                'path' => $path,
//                'error' => $e->getMessage()
//            ]);

            return [
                'current_path' => $path,
                'directories' => [],
                'files' => [],
            ];
        }
    }

    /**
     * دریافت ساختار درختی دایرکتوری‌ها (Options)
     * ساختار خروجی: [Season => [Lesson => [Grade1, Grade2]]]
     */
    public function getDirectoryTree(): array
    {

        try {
//            Log::info('FTP DirectoryTree start');

            $tree = [];

            // level 1: seasons
            $seasonItems = $this->safeListContents('/');

            foreach ($seasonItems as $seasonItem) {
                if (($seasonItem['type'] ?? null) !== 'dir') {
                    continue;
                }

                $seasonPath = '/' . trim($seasonItem['path'], '/');
                $seasonName = basename($seasonPath);

                if (!isset($tree[$seasonName])) {
                    $tree[$seasonName] = [];
                }

                // level 2: lessons
                $lessonItems = $this->safeListContents($seasonPath);

                foreach ($lessonItems as $lessonItem) {
                    if (($lessonItem['type'] ?? null) !== 'dir') {
                        continue;
                    }

                    $lessonPath = '/' . trim($lessonItem['path'], '/');
                    $lessonName = basename($lessonPath);

                    if (!isset($tree[$seasonName][$lessonName])) {
                        $tree[$seasonName][$lessonName] = [];
                    }

                    // level 3: grades
                    $gradeItems = $this->safeListContents($lessonPath);

                    foreach ($gradeItems as $gradeItem) {
                        if (($gradeItem['type'] ?? null) !== 'dir') {
                            continue;
                        }

                        $gradePath = $gradeItem['path'] ?? '';
                        $gradeName = basename($gradePath);

                        $tree[$seasonName][$lessonName][] = $gradeName;
                    }

                    $tree[$seasonName][$lessonName] = array_values(array_unique($tree[$seasonName][$lessonName]));
                    sort($tree[$seasonName][$lessonName]);
                }

                ksort($tree[$seasonName]);
            }

            ksort($tree);

//            Log::info('FTP DirectoryTree result', [
//                'seasons' => count($tree)
//            ]);

            return $tree;

        } catch (\Throwable $e) {
//            Log::error('FTP getDirectoryTree failed', [
//                'error' => $e->getMessage()
//            ]);

            return [];
        }
    }

    private function safeListContents(string $path, int $maxAttempts = 5): array
    {
        $path = '/' . trim($path, '/');
        if ($path === '//') {
            $path = '/';
        }

        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;

            try {
//                Log::info('FTP safeListContents attempt', [
//                    'path' => $path,
//                    'attempt' => $attempt
//                ]);

                $ftpDisk = $this->getStorageDisk();
                $items = $ftpDisk->listContents($path)->toArray();

                if (!empty($items)) {
//                    Log::info('FTP safeListContents success', [
//                        'path' => $path,
//                        'attempt' => $attempt,
//                        'count' => count($items)
//                    ]);

                    return $items;
                }

//                Log::warning('FTP safeListContents empty', [
//                    'path' => $path,
//                    'attempt' => $attempt
//                ]);
            } catch (\Throwable $e) {
//                Log::warning('FTP safeListContents failed', [
//                    'path' => $path,
//                    'attempt' => $attempt,
//                    'error' => $e->getMessage()
//                ]);
            }

            usleep(1000000);
        }

        return [];
    }


    /**
     * تغییر نام فایل
     */
    public function rename(string $oldPath, string $newFilename): string
    {
        $directory = dirname($oldPath);

        $normalized = $this->fileNameService->normalize(
            $newFilename,
            $directory,
            $this->ftpDisk
        );

        $newPath = trim($directory, '/') . '/' . $normalized;

        $success = $this->ftpDisk->move($oldPath, $newPath);

        if (!$success) {
            throw new Exception('خطا در تغییر نام فایل');
        }

        return $newPath;
    }

    /**
     * حذف فایل
     */
    public function delete(string $path): bool
    {
        return $this->ftpDisk->delete($path);
    }

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

        $normalizedFilename = $this->fileNameService->normalize(
            $filename,
            $destinationDirectory,
            $this->ftpDisk
        );

        $newPath = trim($destinationDirectory, '/')
            . '/'
            . $normalizedFilename;

//        Log::info('FTP file move started', [
//            'old_path' => $oldPath,
//            'new_path' => $newPath,
//        ]);

        $success = $this->ftpDisk->move(
            ltrim($oldPath, '/'),
            ltrim($newPath, '/')
        );

        if (!$success) {
            throw new Exception('انتقال فایل در FTP ناموفق بود.');
        }

//        Log::info('FTP file moved', [
//            'old_path' => $oldPath,
//            'new_path' => $newPath
//        ]);

        return '/' . ltrim($newPath, '/');
    }
}
