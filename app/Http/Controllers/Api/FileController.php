<?php

namespace App\Http\Controllers\Api;

use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Enums\PermissionAction;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use App\Services\PermissionService;
use App\Services\FileManagerService;
use App\Services\FileTransferService;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class FileController extends Controller
{
    use ApiResponse;

    protected $transferService;
    protected $managerService;
    protected $permissionService;

    public function __construct(
        FileTransferService $transferService,
        FileManagerService $managerService,
        PermissionService $permissionService
    ) {
        $this->transferService = $transferService;
        $this->managerService = $managerService;
        $this->permissionService = $permissionService;

        $this->middleware('auth:sanctum');
    }

    /**
     * متد خواندن فایل‌ها و مسیرها
     */
    public function browse(Request $request)
    {
        $request->validate([
            'path' => 'nullable|string', // مسیر درخواستی (پیش‌فرض روت)
        ]);

        $path = $request->input('path', '/');
        $user = Auth::user();

        if (!$this->permissionService->checkAccess($user, PermissionAction::READ, $path)) {
            return $this->respondForbidden('شما اجازه مشاهده این مسیر را ندارید.');
        }

        try {
            $cacheKey = 'ftp_list_' . md5($path);
            $data = Cache::remember($cacheKey, 9999, function () use ($path, $user) {
                return $this->managerService->listFiles($path, $user->role);
            });

//            $data = $this->managerService->listFiles($path, $user->role);

            return $this->respondWithSuccess($data);

        } catch (\Exception $e) {
            return $this->respondForbidden($e->getMessage());
        }
    }

    /**
     * دریافت تنظیمات (ساختار فصل‌ها و دروس‌ها از هاست)
     */
    public function getOptions(Request $request)
    {
        $user = Auth::user();

        try {

            $cacheKey = 'ftp_list_options';
            $fullTree = Cache::remember($cacheKey, 60*60*24*365, function () use ($user) {
                // دریافت ساختار کامل از هاست
                return $this->managerService->getDirectoryTree();
            });

            // فیلتر کردن گزینه‌ها بر اساس نقش کاربر
            // اگر ادمین یا پشتیبان است، همه چیز را ببیند
            if ($user->hasRole('admin') || $user->hasRole('support')) {
                return $this->respondWithSuccess($fullTree);
            }

            // اگر دبیر است، باید فقط مسیرهای مجازش را ببیند
            if ($user->hasRole('teacher')) {
                $filteredTree = $this->filterTreeForTeacher($fullTree, $user);
                return $this->respondWithSuccess($filteredTree);
            }

            // سایر نقش‌ها
            return $this->respondWithSuccess([]);

        } catch (\Exception $e) {
            return $this->respondWithError(
                'خطا در دریافت تنظیمات',
                ['detail' => $e->getMessage()],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * فیلتر کردن ساختار درختی بر اساس پرمیشن‌های دبیر
     */
    private function filterTreeForTeacher(array $tree, $user): array
    {
        $filtered = [];

        foreach ($tree as $season => $lessons) {
            foreach ($lessons as $lesson => $grades) {
                foreach ($grades as $grade) {
                    // بررسی پرمیشن خواندن برای این مسیر خاص
                    // از PermissionService استفاده می‌کنیم
                    $path = "{$season}/{$lesson}/{$grade}";

                    if ($this->permissionService->checkAccess($user, PermissionAction::READ, $path)) {
                        // اگر دسترسی داشت، به آرایه اضافه کن
                        if (!isset($filtered[$season])) {
                            $filtered[$season] = [];
                        }
                        if (!isset($filtered[$season][$lesson])) {
                            $filtered[$season][$lesson] = [];
                        }

                        $filtered[$season][$lesson][] = $grade;
                    }
                }
            }
        }

        return $filtered;
    }

    /**
     * متد آپلود (همان متد قبلی با کمی تغییر)
     */
    public function upload(Request $request)
    {
        $request->validate([
            'upload_id' => 'required|string',
            'chunk_index' => 'required|integer',
            'total_chunks' => 'required|integer',
            'filename' => 'required|string',
            'season' => 'required|string',
            'lesson' => 'required|string',
            'grade' => 'required|string',
            'chunk' => 'required|file|max:10240',
        ]);

        $user = Auth::user();
        $path = "{$request->season}/{$request->lesson}/{$request->grade}";

        if (!$this->permissionService->checkAccess($user, PermissionAction::WRITE, $path)) {
            return $this->respondForbidden('شما اجازه آپلود در این مسیر را ندارید.');
        }

        try {
            // ۱. ذخیره تکه روی سرور لاراول (لوکال)
            $this->transferService->storeChunk(
                $request->upload_id,
                $request->chunk_index,
                $request->file('chunk')
            );

            // ۲. بررسی تکمیل بودن آپلود
            $receivedCount = $this->transferService->checkUploadStatus($request->upload_id);

            $response = [
                'upload_id' => $request->upload_id,
                'chunk_index' => $request->chunk_index,
                'received_chunks' => $receivedCount,
                'total_chunks' => $request->total_chunks,
                'complete' => false
            ];

            // ۳. اگر آخرین تکه بود، عملیات انتقال به FTP را آغاز کن
            if ($receivedCount == $request->total_chunks) {

                // ساخت مسیر مقصد روی FTP (مثلا: 1404-04/ریاضی/ششم)
                $destinationPath = "{$request->season}/{$request->lesson}/{$request->grade}";

                $result = $this->transferService->finalizeAndTransfer(
                    $request->upload_id,
                    $request->total_chunks,
                    $request->filename,
                    $destinationPath
                );

                // ذخیره لاگ در دیتابیس (اختیاری)
                // UploadLog::create([...]);

                $response['complete'] = true;
                $response['file_info'] = $result;
            }

            return $this->respondWithSuccess($response);

        } catch (\Exception $e) {
            return $this->respondWithError(
                'خطا در پردازش فایل',
                ['detail' => $e->getMessage()],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * لغو آپلود و پاکسازی فایل‌های موقت
     */
    public function cancel(Request $request)
    {
        $request->validate([
            'upload_id' => 'required|string',
        ]);

        try {
            $this->transferService->cleanupUpload($request->upload_id);

            return $this->respondWithSuccess(
                [
                    'message' => 'آپلود لغو شد و فایل‌های موقت پاک شدند.',
                ],
                Response::HTTP_OK
            );
        } catch (\Exception $e) {
            return $this->respondWithError('خطا در پاکسازی', ['detail' => $e->getMessage()]);
        }
    }

    public function rename(Request $request)
    {
        $data = $request->validate([
            'old_path' => ['required', 'string'],
            'new_name' => ['required', 'string', 'max:255'],
        ]);

        $user = Auth::user();

        if (!$this->permissionService->checkAccess($user, PermissionAction::RENAME, $data['old_path'])) {
            return $this->respondForbidden('شما اجازه تغییر نام در این مسیر را ندارید.');
        }

        try {

            $newPath = $this->managerService->rename($data['old_path'], $data['new_name']);

            \Log::info('FTP file renamed', [
                'old_path' => $data['old_path'],
                'new_path' => $data['new_name']
            ]);

            return $this->respondWithSuccess([
                'ok' => true,
                'new_path' => $newPath,
            ]);

        } catch (\Exception $e) {
            return $this->respondWithError(
                'خطا در تغییر نام',
                ['detail' => $e->getMessage()],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function delete(Request $request, FileManagerService $fileManager)
    {
        $data = $request->validate([
            'path' => ['required', 'string'],
        ]);

        $user = Auth::user();

        if (!$this->permissionService->checkAccess($user, PermissionAction::DELETE, $data['path'])) {
            return $this->respondForbidden('شما اجازه حذف فایل در این مسیر را ندارید.');
        }

        try {

            $ok = $fileManager->delete($data['path']);

            \Log::info('FTP file deleted', [
                'path' => $data['path'],
            ]);

            return $this->respondWithSuccess([
                'ok' => (bool) $ok,
            ]);

        } catch (\Exception $e) {
            return $this->respondWithError(
                'خطا در حذف فایل',
                ['detail' => $e->getMessage()],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function clearBrowseCache(Request $request)
    {
        $data = $request->validate([
            'path' => 'nullable|string',
        ]);

        $path = $data['path'] ?? '/';
        $user = Auth::user();

        if (!$this->permissionService->checkAccess($user, PermissionAction::READ, $path)) {
            return $this->respondForbidden('شما اجازه پاک کردن کش این مسیر را ندارید.');
        }

        try {
            $cacheKey = 'ftp_list_' . md5($path);

            Cache::forget($cacheKey);

            return $this->respondWithSuccess([
                'ok' => true,
                'path' => $path,
                'cache_key' => $cacheKey,
            ]);

        } catch (\Exception $e) {
            return $this->respondWithError(
                'خطا در پاک کردن کش مسیر',
                ['detail' => $e->getMessage()],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function clearOptionsCache(Request $request)
    {
        $user = Auth::user();

        if (!$user->hasRole('admin') && !$user->hasRole('support')) {
            return $this->respondForbidden('شما اجازه پاک کردن کش تنظیمات را ندارید.');
        }

        try {
            $cacheKey = 'ftp_list_options';

            Cache::forget($cacheKey);

            return $this->respondWithSuccess([
                'ok' => true,
                'cache_key' => $cacheKey,
            ]);

        } catch (\Exception $e) {
            return $this->respondWithError(
                'خطا در پاک کردن کش تنظیمات',
                ['detail' => $e->getMessage()],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

}
