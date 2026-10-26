<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Enums\PermissionAction;
use App\Enums\FileActivityStatus;
use App\Services\PermissionService;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use App\Services\FileManagerService;
use App\Services\FileTransferService;
use App\Services\FileActivityLogger;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class FileController extends Controller
{
    use ApiResponse;

    protected $transferService;
    protected $managerService;
    protected $permissionService;
    protected $activityLogger;

    public function __construct(
        FileTransferService $transferService,
        FileManagerService $managerService,
        PermissionService $permissionService,
        FileActivityLogger $activityLogger
    ) {
        $this->transferService = $transferService;
        $this->managerService = $managerService;
        $this->permissionService = $permissionService;
        $this->activityLogger = $activityLogger;

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

        $path = $this->permissionService->normalizeBrowsePath($request->input('path', '/'));
        $user = Auth::user();

        try {
            if (!$this->permissionService->checkAccess($user, PermissionAction::READ, $path)) {
                return $this->respondForbidden('شما اجازه مشاهده این مسیر را ندارید.');
            }

            $cacheKey = $this->browseCacheKey($path);
            $data = Cache::remember($cacheKey, 60*60*24*30, function () use ($path, $user) {
                return $this->managerService->listFiles($path);
            });

            // فیلتر کردن لیست برای دبیر
            if ($user->hasRole(UserRole::TEACHER->value)) {
                $data['directories'] = $this->permissionService->filterDirectoriesForTeacher(
                    $data['directories'],
                    $user,
                    PermissionAction::READ,
                    $path
                );
            }

            return $this->respondWithSuccess($data);

        } catch (\Exception $e) {
//            return $this->respondForbidden($e->getMessage());
            return $this->respondForbidden('شما اجازه مشاهده این مسیر را ندارید.');
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
                $filteredTree = $this->permissionService->filterTreeForTeacher($fullTree, $user);
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
     * متد آپلود (همان متد قبلی با کمی تغییر)
     */
    public function upload(Request $request)
    {
        $request->validate([
            'upload_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]+$/'],
            'chunk_index' => 'required|integer',
            'total_chunks' => 'required|integer',
            'filename' => 'required|string',
            'season' => 'required|string',
            'lesson' => 'required|string',
            'grade' => 'required|string',
            'chunk' => 'required|file|max:10240',
        ]);

        $user = Auth::user();
        $path = $this->permissionService->normalizeBrowsePath("{$request->season}/{$request->lesson}/{$request->grade}");

        if (!$this->permissionService->checkAccess($user, PermissionAction::WRITE, $path)) {
            return $this->respondForbidden('شما اجازه آپلود در این مسیر را ندارید.');
        }
        // ساخت مسیر مقصد روی FTP (مثلا: 1404-04/ریاضی/ششم)
        $destinationPath = $this->permissionService->normalizeBrowsePath(
            "{$request->season}/{$request->lesson}/{$request->grade}"
        );
        try {
            // ۱. ذخیره تکه روی سرور لاراول (لوکال)
            $this->transferService->storeChunk(
                $request->upload_id,
                $request->chunk_index,
                $request->file('chunk')
            );

            // ۲. بررسی تکمیل بودن آپلود
//            $receivedCount = $this->transferService->checkUploadStatus($request->upload_id);

            $status = $this->transferService->checkUploadStatus(
                $request->upload_id,
                $request->total_chunks
            );

            $response = [
                'upload_id' => $request->upload_id,
                'chunk_index' => $request->chunk_index,
//                'received_chunks' => $receivedCount,
                'received_chunks' => $status['received_count'],
                'total_chunks' => $request->total_chunks,
                'complete' => $status['complete'],
//                'complete' => false
            ];

            // ۳. اگر آخرین تکه بود، عملیات انتقال به FTP را آغاز کن
//            if ($receivedCount == $request->total_chunks) {
            if ($status['complete']) {
                $result = $this->transferService->finalizeAndTransfer(
                    $request->upload_id,
                    $request->total_chunks,
                    $request->filename,
                    $destinationPath
                );

                $this->flushDirectory($destinationPath);

                $this->activityLogger->log('upload', FileActivityStatus::SUCCESS, [
                    'path'        => "{$destinationPath}/{$request->filename}",
                    'file_name'   => $request->filename,
                    'upload_id'   => $request->upload_id,
                    'file_size'   => $result['size'] ?? null,
                ]);

                $response['complete'] = true;
                $response['file_info'] = $result;
            }

            return $this->respondWithSuccess($response);

        } catch (\Exception $e) {
            $this->activityLogger->log('upload', FileActivityStatus::FAILED, [
                'path' => "{$destinationPath}/{$request->filename}",
                'file_name' => $request->filename,
                'upload_id' => $request->upload_id,
                'error_message' => $e->getMessage(),
                'meta' => [
                    'chunk_index' => $request->chunk_index,
                    'total_chunks' => $request->total_chunks,
                    'season' => $request->season,
                    'lesson' => $request->lesson,
                    'grade' => $request->grade,
                ],
            ]);

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
            'upload_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]+$/'],
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

        $newPath = null;

        try {
            $newPath = $this->managerService->rename(
                $data['old_path'],
                $data['new_name']
            );

            $this->flushDirectoryOfFile($data['old_path']);
            $this->flushDirectoryOfFile($newPath);

            $this->activityLogger->log('rename', FileActivityStatus::SUCCESS, [
                'source_path'      => $data['old_path'],
                'destination_path' => $newPath,
                'file_name'        => $data['new_name'],
            ]);

//            \Log::info('FTP file renamed', [
//                'old_path' => $data['old_path'],
//                'new_path' => $data['new_name']
//            ]);

            return $this->respondWithSuccess([
                'ok' => true,
                'new_path' => $newPath,
            ]);

        } catch (\Exception $e) {
            $this->activityLogger->log('rename', FileActivityStatus::FAILED, [
                'source_path' => $data['old_path'],
                'destination_path' => $newPath,
                'file_name' => $data['new_name'],
                'error_message' => $e->getMessage(),
            ]);

//            \Log::error('FTP file rename failed', [
//                'old_path' => $data['old_path'],
//                'new_name' => $data['new_name'],
//                'error' => $e->getMessage(),
//            ]);

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

            if (!$ok) {
                throw new \RuntimeException(
                    'حذف فایل از FTP با موفقیت انجام نشد.'
                );
            }

            $this->flushDirectoryOfFile($data['path']);

            $this->activityLogger->log('delete', FileActivityStatus::SUCCESS, [
                'path'      => $data['path'],
                'file_name' => basename($data['path']),
            ]);

//            \Log::info('FTP file deleted', [
//                'path' => $data['path'],
//            ]);

            return $this->respondWithSuccess([
                'ok' => (bool) $ok,
            ]);

        } catch (\Exception $e) {
            $this->activityLogger->log('delete', FileActivityStatus::FAILED, [
                'path' => $data['path'],
                'file_name' => basename($data['path']),
                'error_message' => $e->getMessage(),
            ]);

//            \Log::error('FTP file delete failed', [
//                'path' => $data['path'],
//                'error' => $e->getMessage(),
//            ]);

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

        $path = $this->permissionService->normalizeBrowsePath($data['path'] ?? '/');
        $user = Auth::user();

        if (!$this->permissionService->checkAccess($user, PermissionAction::READ, $path)) {
            return $this->respondForbidden('شما اجازه پاک کردن کش این مسیر را ندارید.');
        }

        try {
            $this->flushDirectory($path);

            return $this->respondWithSuccess([
                'ok' => true,
                'path' => $path
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

    public function move(Request $request)
    {
        $data = $request->validate([
            'old_path' => ['required', 'string'],
            'destination_path' => ['required', 'string'],
        ]);

        $oldPath = $this->permissionService->normalizeBrowsePath($data['old_path']);
        $destinationPath = $this->permissionService->normalizeBrowsePath($data['destination_path']);

        $user = Auth::user();

        if (
            !$this->permissionService->checkAccess(
                $user,
                PermissionAction::MOVE,
                $oldPath
            )
        ) {
            return $this->respondForbidden(
                'شما اجازه دسترسی به فایل مبدأ را ندارید.'
            );
        }

        if (
            !$this->permissionService->checkAccess(
                $user,
                PermissionAction::MOVE,
                $destinationPath
            )
        ) {
            return $this->respondForbidden(
                'شما اجازه انتقال فایل به مسیر مقصد را ندارید.'
            );
        }

        try {
            $newPath = $this->managerService->move(
                $oldPath,
                $destinationPath
            );

            // فقط بعد از موفقیت انتقال، کش مبدأ و مقصد پاک شود.
            $this->flushDirectoryOfFile($oldPath);
            $this->flushDirectory($destinationPath);


            $this->activityLogger->log('move', FileActivityStatus::SUCCESS, [
                'source_path'      => $oldPath,
                'destination_path' => $destinationPath,
                'file_name'        => basename($oldPath),
            ]);

            return $this->respondWithSuccess([
                'ok' => true,
                'old_path' => $oldPath,
                'new_path' => $newPath
            ]);
        } catch (\Throwable $e) {
//            \Log::error('FTP file move failed', [
//                'old_path' => $data['old_path'],
//                'destination_path' => $data['destination_path'],
//                'error' => $e->getMessage(),
//            ]);

            $this->activityLogger->log('upload', FileActivityStatus::FAILED, [
                'upload_id'     => $request->upload_id,
                'file_name'     => $request->filename,
                'error_message' => $e->getMessage(),
            ]);

            return $this->respondWithError(
                'خطا در انتقال فایل',
                ['detail' => $e->getMessage()],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    private function browseCacheKey(string $path): string
    {
        $normalizedPath = $this->permissionService->normalizeBrowsePath($path);

        return FileTransferService::getListCacheKey($normalizedPath);
    }

    private function directoryOfFile(string $filePath): string
    {
        $normalizedPath = $this->permissionService->normalizeBrowsePath($filePath);

        return $this->permissionService->normalizeBrowsePath(dirname($normalizedPath));
    }

    private function flushDirectoryOfFile(string $filePath)
    {
        $directory = $this->directoryOfFile($filePath);
        $cacheKey = $this->browseCacheKey($directory);
        Cache::forget($cacheKey);

//        \Log::info('Browse cache cleared', [
//            'directory' => $directory,
//            'cache_key' => $cacheKey
//        ]);
    }

    private function flushDirectory(string $directoryPath): void
    {
        $directory = $this->permissionService->normalizeBrowsePath($directoryPath);
        $cacheKey = $this->browseCacheKey($directory);

        Cache::forget($cacheKey);

//        \Log::info('Browse cache cleared', [
//            'directory' => $directory,
//            'cache_key' => $cacheKey
//        ]);
    }

}
