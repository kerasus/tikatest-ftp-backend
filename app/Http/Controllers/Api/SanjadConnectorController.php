<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SanjadSyncService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SanjadConnectorController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected SanjadSyncService $sanjadSyncService
    ) {}

    /**
     * دریافت لیست کلاس‌ها از سنجاد برای پنل مدیریت کراسوس (توسط ادمین)
     */
    public function getClasses(Request $request): JsonResponse
    {
        try {
            $classes = $this->sanjadSyncService->fetchClassesFromSanjad();
            return $this->respondWithSuccess($classes);
        } catch (\Exception $e) {
            return $this->respondWithError(
                'خطا در ارتباط با سرور سنجاد',
                ['detail' => $e->getMessage()],
                502
            );
        }
    }

    /**
     * پاسخ به درخواست سرور سنجاد برای گرفتن فایل‌های دسترسی‌دار یک کلاس خاص
     */
    public function getFilesByClassId(int $classId): JsonResponse
    {
        $data = $this->sanjadSyncService->getClassFilesTree($classId);

        if (!$data) {
            return $this->respondWithError(
                'کلاس مورد نظر در سیستم FTP یافت نشد یا دسترسی ندارد.',
                null,
                404
            );
        }

        return $this->respondWithSuccess($data);
    }
}
