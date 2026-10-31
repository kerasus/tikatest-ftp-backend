<?php

namespace App\Services;

use App\Models\SanjadClass;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class SanjadSyncService
{
    public function __construct(
        protected FileManagerService $fileManagerService
    ) {}

    /**
     * ۱. دریافت لیست کلاس‌ها از سرور سنجاد
     */
    public function fetchClassesFromSanjad(): array
    {
        $token = config('services.sanjad.token');
        $address = rtrim(config('services.sanjad.base_url'), '/') . '/classes';
        $response = Http::acceptJson()
            ->withHeaders([
                'X-School-Service-Key' => $token,
            ])
            ->timeout(15)
            ->get($address);

        $response->throw();

        return $response->json();
    }

    /**
     * ۲. پاسخ به درخواست سنجاد: استخراج و ساخت لیست درختی فایل‌های یک کلاس بر اساس پرمیشن‌هایش
     */
    public function getClassFilesTree(int $sanjadClassId): ?array
    {
        $class = SanjadClass::query()
            ->with(['permissions' => function ($q) {
                $q->where('name', 'like', 'read#%');
            }])
            ->where('sanjad_class_id', $sanjadClassId)
            ->first();

        if (!$class) {
            return null;
        }

        $tree = [];

        foreach ($class->permissions as $permission) {
            // ساختار پرمیشن: read#season#lesson#grade
            $segments = explode('#', $permission->name);

            if (count($segments) !== 4 || $segments[0] !== 'read') {
                continue;
            }

            [$action, $season, $lesson, $grade] = $segments;

            // مسیر نسبی روی FTP بر اساس ساختار موجود شما
            $relativePath = "/{$season}/{$lesson}/{$grade}";

            // استفاده از متد آماده‌ی listFiles خودت در FileManagerService
            $result = $this->fileManagerService->listFiles($relativePath);

            // ساختار درختی خروجی برای این پرمیشن
            if (!isset($tree[$season])) {
                $tree[$season] = [];
            }
            if (!isset($tree[$season][$lesson])) {
                $tree[$season][$lesson] = [];
            }

            $tree[$season][$lesson][$grade] = [
                'permission' => $permission->name,
                'path' => $result['current_path'],
                'directories' => $result['directories'],
                'files' => $result['files'],
            ];
        }

        return [
            'class_id' => $class->sanjad_class_id,
            'class_name' => $class->name,
            'tree' => $tree,
        ];
    }

    /**
     * ۳. استخراج و تجمیع درختی فایل‌های مجموعه‌ای از کلاس‌ها (Merged Tree)
     */
    public function getClassesFilesTree(array $sanjadClassIds): array
    {
        $correlationId = request()->header('X-Correlation-Id', 'n/a');
        \Log::withContext(['correlation_id' => $correlationId]);

        $t0 = hrtime(true);
        $validClassIds = array_values(array_unique(array_filter($sanjadClassIds, fn($id) => is_numeric($id) && $id > 0)));

        if (empty($validClassIds)) {
            return [
                'class_ids' => [],
                'tree'      => [],
            ];
        }

        // ۱. زمان‌سنجی کوئری دیتابیس
        $tDb = hrtime(true);
        $classes = SanjadClass::query()
            ->with(['permissions' => function ($q) {
                $q->where('name', 'like', 'read#%');
            }])
            ->whereIn('sanjad_class_id', $validClassIds)
            ->get();
        $dbMs = (int) ((hrtime(true) - $tDb) / 1e6);

        $tree = [];
        $ftpPathCache = [];
        $ftpTimings = [];
        $permissionCount = 0;

        foreach ($classes as $class) {
            foreach ($class->permissions as $permission) {
                $permissionCount++;

                $segments = explode('#', $permission->name);
                if (count($segments) !== 4 || $segments[0] !== 'read') {
                    continue;
                }

                [$action, $season, $lesson, $grade] = $segments;
                $relativePath = "/{$season}/{$lesson}/{$grade}";

                // اگر قبلاً در همین ریکوئست پردازش نشده
                if (!array_key_exists($relativePath, $ftpPathCache)) {
                    $tFtp = hrtime(true);
                    $cacheKey = self::getFtpCacheKey($relativePath);
                    $fromPersistentCache = Cache::has($cacheKey);

                    try {
                        // کش ۱۲ ساعته برای درختی که خواسته بودی
                        $pathResult = Cache::remember($cacheKey, now()->addHours(12), function () use ($relativePath) {
                            $res = $this->fileManagerService->listFiles($relativePath);
                            $res['files'] = $this->attachUrlToFiles($res['files'] ?? []);
                            return $res;
                        });

                        $ftpMs = (int) ((hrtime(true) - $tFtp) / 1e6);
                        $ftpTimings[] = [
                            'path'   => $relativePath,
                            'ms'     => $ftpMs,
                            'cached' => $fromPersistentCache,
                        ];

                        $ftpPathCache[$relativePath] = $pathResult;
                    } catch (\Throwable $e) {
                        $ftpMs = (int) ((hrtime(true) - $tFtp) / 1e6);
                        $ftpTimings[] = [
                            'path'   => $relativePath,
                            'ms'     => $ftpMs,
                            'status' => 'error',
                        ];
                        Log::warning("خطا در خواندن مسیر FTP {$relativePath}: " . $e->getMessage());
                        $ftpPathCache[$relativePath] = [
                            'current_path' => $relativePath,
                            'directories'  => [],
                            'files'        => [],
                        ];
                    }
                }

                $pathResult = $ftpPathCache[$relativePath];

                if (!isset($tree[$season])) {
                    $tree[$season] = [];
                }
                if (!isset($tree[$season][$lesson])) {
                    $tree[$season][$lesson] = [];
                }

                // تجمیع در شاخه درختی
                if (!isset($tree[$season][$lesson][$grade])) {
                    $tree[$season][$lesson][$grade] = [
                        'permissions' => [$permission->name],
                        'class_ids'   => [$class->sanjad_class_id],
                        'path'        => $pathResult['current_path'] ?? $relativePath,
                        'directories' => $pathResult['directories'] ?? [],
                        'files'       => $pathResult['files'] ?? [],
                    ];
                } else {
                    $tree[$season][$lesson][$grade]['class_ids'] = array_values(
                        array_unique(array_merge($tree[$season][$lesson][$grade]['class_ids'], [$class->sanjad_class_id]))
                    );
                    $tree[$season][$lesson][$grade]['permissions'] = array_values(
                        array_unique(array_merge($tree[$season][$lesson][$grade]['permissions'], [$permission->name]))
                    );
                }
            }
        }

        $totalMs = (int) ((hrtime(true) - $t0) / 1e6);

        // مرتب‌سازی کندترین مسیرها
        usort($ftpTimings, fn($a, $b) => $b['ms'] <=> $a['ms']);

        \Log::info('krasus.tree.summary', [
            'total_ms'        => $totalMs,
            'db_ms'           => $dbMs,
            'ftp_calls_count' => count($ftpTimings),
            'permissions_tot' => $permissionCount,
            'slowest_paths'   => array_slice($ftpTimings, 0, 5),
        ]);

        return [
            'class_ids' => $validClassIds,
            'tree'      => $tree,
        ];
    }

    public static function getFtpCacheKey(string $path): string
    {
        // اینطوری همیشه خیالت راحته که کلیدها یکسان تولید میشن
        return 'ftp_path_tree_' . md5('/' . trim($path, '/'));
    }

    /**
     * افزودن آدرس کامل دانلود (URL) به لیست فایل‌ها بر اساس کانفیگ
     *
     * @param array $files
     * @return array
     */
    protected function attachUrlToFiles(array $files): array
    {
        // خواندن Base URL از کانفیگ (می‌توانی کلید دلخواهت در config/filesystems.php یا services.php را بگذاری)
        $baseUrl = 'https://dl.asadikia.ir/archive' . rtrim(config('filesystems.disks.ftp.url', config('services.ftp.base_url', '')), '/');

        if (empty($baseUrl)) {
            return $files;
        }

        return array_map(function (array $file) use ($baseUrl): array {
            $rawPath = ltrim($file['path'] ?? '', '/');

            // انکود کردن سگمنت‌های فارسی و فاصله‌ها برای جلوگیری از خطای ۴۰۰/۴۰۴ در مرورگر
            $encodedPath = implode('/', array_map('rawurlencode', explode('/', $rawPath)));
            $file['url'] = "{$baseUrl}/{$encodedPath}";
            return $file;
        }, $files);
    }
}
