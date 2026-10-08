<?php
namespace App\Services;

use App\Models\SanjadClass;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        $response = Http::acceptJson()
            ->withHeaders([
                'X-School-Service-Key' => config('services.sanjad.token'),
            ])
            ->timeout(15)
            ->get(rtrim(config('services.sanjad.base_url'), '/') . '/classes');

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
}
