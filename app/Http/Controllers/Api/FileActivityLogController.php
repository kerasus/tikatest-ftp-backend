<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\FileActivityLog;
use App\Traits\ApiResponse;
use App\Traits\CommonCRUD;
use App\Traits\Filter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FileActivityLogController extends Controller
{
    use Filter, CommonCRUD, ApiResponse;

    public function __construct()
    {
        $this->middleware('auth:sanctum');

        /*
         * لاگ عملیات حساس است؛ فقط ادمین و پشتیبان بتوانند آن را ببینند.
         * اگر می‌خواهید دبیر هم صرفاً لاگ‌های خودش را مشاهده کند،
         * باید منطق index را کمی تغییر دهیم.
         */
        $this->middleware(function ($request, $next) {
            $user = $request->user();

            if (!$user->hasAnyRole([
                UserRole::ADMIN,
                UserRole::SUPPORT,
            ])) {
                return $this->respondForbidden('دسترسی غیرمجاز');
            }

            return $next($request);
        });
    }

    /**
     * لیست فعالیت‌های فایل همراه با فیلتر، مرتب‌سازی و صفحه‌بندی
     */
    public function index(Request $request): JsonResponse
    {
        $config = [
            'filterKeys' => [
                'action',
                'status',
                'path',
                'source_path',
                'destination_path',
                'file_name',
                'upload_id',
                'ip_address',
                'error_message',
            ],
            'filterKeysExact' => [
                'user_id',
                'file_size',
            ],
            'filterDate' => [
                'created_at',
            ],
            'filterRelationKeys' => [
                [
                    'requestKey' => 'user_firstname',
                    'relationName' => 'user',
                    'relationColumn' => 'firstname',
                    'exact' => false,
                ],
                [
                    'requestKey' => 'user_lastname',
                    'relationName' => 'user',
                    'relationColumn' => 'lastname',
                    'exact' => false,
                ],
                [
                    'requestKey' => 'user_username',
                    'relationName' => 'user',
                    'relationColumn' => 'username',
                    'exact' => false,
                ]
            ],
            'eagerLoads' => [
                'user',
            ],
        ];

        return $this->commonIndex(
            $request,
            FileActivityLog::class,
            $config
        );
    }

    /**
     * مشاهده جزئیات یک لاگ مشخص
     */
    public function show(FileActivityLog $fileActivityLog): JsonResponse
    {
        $fileActivityLog->load([
            'user:id,firstname,lastname,username,email',
        ]);

        return $this->respondWithSuccess($fileActivityLog);
    }
}
