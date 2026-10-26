<?php

namespace App\Http\Controllers\Api;

use App\Enums\PermissionAction;
use App\Http\Controllers\Controller;
use App\Models\SanjadClass;
use App\Services\PermissionService;
use App\Services\SanjadSyncService;
use App\Traits\ApiResponse;
use App\Traits\CommonCRUD;
use App\Traits\Filter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpFoundation\Response;

class SanjadConnectorController extends Controller
{
    use ApiResponse, CommonCRUD, Filter;

    public function __construct(
        protected SanjadSyncService $sanjadSyncService,
        protected PermissionService $permissionService
    ) {
        $this->middleware('role:admin')->except([
            'getFilesByClassId',
            'getFilesByClassIds'
        ]);
    }

    /**
     * دریافت لیست کلاس‌های ذخیره‌شده سنجاد
     */
    public function index(Request $request): JsonResponse
    {
        $config = [
            'filterKeys' => [
                'name',
                'academic_level_name',
                'academic_field_name',
            ],
            'filterKeysExact' => [
                'sanjad_class_id',
                'is_deleted_at_source',
            ],
        ];

        return $this->commonIndex($request, SanjadClass::class, $config);
    }

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
     * همگام‌سازی کلاس‌های ذخیره‌شده با آخرین اطلاعات سنجاد
     */
    public function syncClasses(): JsonResponse
    {
        try {
            $sourceClasses = collect($this->sanjadSyncService->fetchClassesFromSanjad())
                ->mapWithKeys(function ($sourceClass, $index) {
                    if (
                        ! is_array($sourceClass) ||
                        ! isset($sourceClass['id']) ||
                        ! is_numeric($sourceClass['id']) ||
                        ! isset($sourceClass['name']) ||
                        ! is_string($sourceClass['name'])
                    ) {
                        throw new \UnexpectedValueException(
                            "ساختار کلاس دریافتی از سنجاد در ردیف {$index} معتبر نیست."
                        );
                    }

                    return [(int) $sourceClass['id'] => $sourceClass];
                });

            $result = DB::transaction(function () use ($sourceClasses) {
                $sourceClassIds = $sourceClasses->keys()->all();
                $deletedQuery = SanjadClass::query()
                    ->where('is_deleted_at_source', false);

                if (count($sourceClassIds) > 0) {
                    $deletedQuery->whereNotIn('sanjad_class_id', $sourceClassIds);
                }

                $deletedCount = $deletedQuery->update([
                    'is_deleted_at_source' => true,
                ]);

                $createdCount = 0;
                $updatedCount = 0;
                $restoredCount = 0;

                foreach ($sourceClasses as $sanjadClassId => $sourceClass) {
                    $existingClass = SanjadClass::query()
                        ->where('sanjad_class_id', $sanjadClassId)
                        ->first();

                    $wasDeletedAtSource = $existingClass?->is_deleted_at_source === true;

                    $class = SanjadClass::query()->updateOrCreate(
                        [
                            'sanjad_class_id' => $sanjadClassId,
                        ],
                        [
                            'name' => $sourceClass['name'],
                            'academic_level_name' => data_get($sourceClass, 'academic_level.name'),
                            'academic_field_name' => data_get($sourceClass, 'academic_level.academic_field.name'),
                            'is_deleted_at_source' => false,
                        ]
                    );

                    if ($class->wasRecentlyCreated) {
                        $createdCount++;
                    } elseif ($class->wasChanged()) {
                        $updatedCount++;
                    }

                    if ($wasDeletedAtSource) {
                        $restoredCount++;
                    }
                }

                return [
                    'source_count' => $sourceClasses->count(),
                    'created_count' => $createdCount,
                    'updated_count' => $updatedCount,
                    'restored_count' => $restoredCount,
                    'deleted_count' => $deletedCount,
                ];
            });

            return $this->respondWithSuccess([
                'message' => 'کلاس‌های سنجاد با موفقیت به‌روزرسانی شدند.',
                ...$result,
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->respondWithError(
                'خطا در همگام‌سازی کلاس‌های سنجاد',
                ['detail' => $exception->getMessage()],
                Response::HTTP_BAD_GATEWAY
            );
        }
    }

    /**
     * دریافت دسترسی‌های مشاهده فایل یک کلاس
     */
    public function classPermissions(SanjadClass $sanjadClass): JsonResponse
    {
        return $this->respondWithSuccess([
            'class' => $sanjadClass,
            'permissions' => $sanjadClass->permissions()
                ->where('guard_name', 'web')
                ->where('name', 'like', PermissionAction::READ->value.'#%')
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'guard_name',
                ]),
        ]);
    }

    /**
     * افزودن دسترسی مشاهده فایل به کلاس
     */
    public function grantClassPermission(Request $request, SanjadClass $sanjadClass): JsonResponse
    {
        $validated = $this->validateClassPermission($request);
        $permissionName = $this->getClassPermissionName($validated);
        $permission = Permission::firstOrCreate([
            'name' => $permissionName,
            'guard_name' => 'web',
        ]);

        $sanjadClass->givePermissionTo($permission);

        return $this->respondWithSuccess([
            'message' => 'دسترسی مشاهده با موفقیت به کلاس اضافه شد.',
            'permission' => [
                'id' => $permission->id,
                'name' => $permission->name,
                'guard_name' => $permission->guard_name,
            ],
        ]);
    }

    /**
     * حذف دسترسی مشاهده فایل از کلاس
     */
    public function revokeClassPermission(Request $request, SanjadClass $sanjadClass): JsonResponse
    {
        $validated = $this->validateClassPermission($request);
        $permissionName = $this->getClassPermissionName($validated);
        $permission = Permission::query()
            ->where('name', $permissionName)
            ->where('guard_name', 'web')
            ->first();

        if (! $permission) {
            return $this->respondNotFound('این دسترسی وجود ندارد.');
        }

        $sanjadClass->revokePermissionTo($permission);

        return $this->respondWithSuccess([
            'message' => 'دسترسی مشاهده با موفقیت از کلاس حذف شد.',
            'permission' => $permissionName,
        ]);
    }

    /**
     * پاسخ به درخواست سرور سنجاد برای گرفتن فایل‌های دسترسی‌دار یک کلاس خاص
     */
    public function getFilesByClassId(int $classId): JsonResponse
    {
        $data = $this->sanjadSyncService->getClassFilesTree($classId);

        if (! $data) {
            return $this->respondWithError(
                'کلاس مورد نظر در سیستم FTP یافت نشد یا دسترسی ندارد.',
                null,
                404
            );
        }

        return $this->respondWithSuccess($data);
    }

    /**
     * پاسخ به درخواست سرور سنجاد برای دریافت تجمیعی فایل‌های مجموعه‌ای از کلاس‌ها
     */
    public function getFilesByClassIds(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'class_ids'   => ['required', 'array', 'min:1'],
            'class_ids.*' => ['integer', 'distinct'],
        ]);

        $data = $this->sanjadSyncService->getClassesFilesTree($validated['class_ids']);

        // اگر درختی وجود نداشت یا هیچ کلاسی مچ نشد
        if (empty($data['tree'])) {
            return $this->respondWithError(
                'هیچ فایلی برای کلاس‌های درخواستی یافت نشد یا کلاس‌ها دسترسی معتبری ندارند.',
                [
                    'class_ids' => $validated['class_ids'],
                    'tree'      => (object) [],
                ],
                404
            );
        }

        return $this->respondWithSuccess($data['tree']);
    }

    private function validateClassPermission(Request $request): array
    {
        return $request->validate([
            'action' => [
                'required',
                'string',
                Rule::in([PermissionAction::READ->value]),
            ],
            'season' => [
                'required',
                'string',
                'max:255',
            ],
            'lesson' => [
                'nullable',
                'string',
                'max:255',
            ],
            'grade' => [
                'nullable',
                'string',
                'max:255',
            ],
        ]);
    }

    private function getClassPermissionName(array $validated): string
    {
        return $this->permissionService->getPermissionName(
            PermissionAction::READ,
            $validated['season'],
            $validated['lesson'] ?? null,
            $validated['grade'] ?? null
        );
    }
}
