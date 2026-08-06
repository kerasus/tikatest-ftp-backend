<?php

namespace App\Http\Controllers\Api;

use Spatie\Permission\Models\Permission;
use App\Services\PermissionService;
use App\Enums\PermissionAction;
use App\Traits\Filter;
use App\Traits\CommonCRUD;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;
use App\Models\User;
use App\Enums\UserRole;
use Illuminate\Validation\Rule;


class PermissionController extends Controller
{
    use Filter, CommonCRUD, ApiResponse;

    protected $permissionService;

    public function __construct(PermissionService $permissionService)
    {
        $this->permissionService = $permissionService;

        // Apply authorization middleware
        $this->middleware('auth:sanctum');

        // فقط ادمین می‌تواند پرمیشن‌ها را ایجاد، ویرایش و حذف کند
        $this->middleware('role:admin')->only(['store', 'update', 'destroy']);

        // ادمین و پشتیبان می‌توانند لیست پرمیشن‌ها را ببینند
        $this->middleware(function ($request, $next) {
            $user = $request->user();
            if (!$user->hasAnyRole(['admin', 'support'])) {
                return $this->respondForbidden('دسترسی غیرمجاز');
            }
            return $next($request);
        })->only(['index', 'show']);
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $config = [
            'filterKeys' => [
                'name',
                'guard_name'
            ],
            'scopes' => [
                // اگر اسکوپی برای فیلتر بر اساس نام دارید می‌توانید اضافه کنید
            ]
        ];

        return $this->commonIndex($request, Permission::class, $config);
    }

    /**
     * Store a newly created resource in storage.
     * از سرویس PermissionService برای ساخت نام پرمیشن استفاده می‌کند.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        // اعتبارسنجی ورودی‌ها
        $request->validate([
            'action' => 'required|string|in:read,write,delete',
            'season' => 'required|string',
            'lesson' => 'nullable|string',
            'grade'  => 'nullable|string',
        ]);

        try {
            // تبدیل اکشن رشته‌ای به Enum
            $actionEnum = PermissionAction::from($request->action);

            // استفاده از سرویس برای ساخت نام پرمیشن (action#season#lesson#grade)
            $permissionName = $this->permissionService->getPermissionName(
                $actionEnum,
                $request->season,
                $request->lesson ?? null,
                $request->grade ?? null
            );

            // بررسی تکراری نبودن پرمیشن
            $existingPermission = Permission::where('name', $permissionName)->first();
            if ($existingPermission) {
                return $this->respondWithError(
                    'این دسترسی قبلاً ثبت شده است.',
                    null,
                    Response::HTTP_CONFLICT
                );
            }

            // آماده‌سازی داده‌ها برای CommonCRUD
            // ما نام پرمیشن ساخته شده را به درخواست اضافه می‌کنیم تا در متد commonStore استفاده شود
            $request->merge([
                'name' => $permissionName,
                'guard_name' => 'web'
            ]);

            return $this->commonStore($request, Permission::class);

        } catch (\ValueError $e) {
            return $this->respondWithError(
                'Invalid action provided.',
                null,
                Response::HTTP_BAD_REQUEST
            );
        } catch (\Exception $e) {
            return $this->respondWithError(
                'Error creating permission.',
                ['detail' => $e->getMessage()],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $permission = Permission::findOrFail($id);
        return $this->respondWithSuccess($permission);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Request $request
     * @param Permission $permission
     * @return JsonResponse
     */
    public function update(Request $request, Permission $permission): JsonResponse
    {
        // نکته: معمولاً نام پرمیشن نباید تغییر کند چون ساختار دارد.
        // اما اگر نیاز به تغییر guard_name یا چیز دیگر دارید:
        $request->validate([
            'guard_name' => 'sometimes|required|string',
        ]);

        return $this->commonUpdate($request, $permission);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Permission $permission
     * @return JsonResponse
     */
    public function destroy(Permission $permission): JsonResponse
    {
        return $this->commonDestroy($permission);
    }

    /**
     * دریافت پرمیشن‌های مستقیم یک کاربر
     */
    public function userPermissions(User $user): JsonResponse
    {
        return $this->respondWithSuccess([
            'user' => [
                'id' => $user->id,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'username' => $user->username,
            ],
            'permissions' => $user->permissions()
                ->where('guard_name', 'web')
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'guard_name',
                ]),
        ]);
    }

    /**
     * افزودن یک دسترسی مسیرمحور به دبیر
     */
    public function grantUserPermission(Request $request, User $user): JsonResponse
    {
        if (!$user->hasRole(UserRole::TEACHER->value)) {
            return $this->respondWithError(
                'فقط برای دبیر می‌توان دسترسی مسیرمحور تعریف کرد.',
                null,
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $actions = collect(PermissionAction::cases())
            ->pluck('value')
            ->all();

        $validated = $request->validate([
            'action' => [
                'required',
                'string',
                Rule::in($actions),
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

        $permissionName = $this->permissionService->getPermissionName(
            PermissionAction::from($validated['action']),
            $validated['season'],
            $validated['lesson'] ?? null,
            $validated['grade'] ?? null
        );

        $permission = Permission::firstOrCreate(
            [
                'name' => $permissionName,
                'guard_name' => 'web',
            ]
        );

        $user->givePermissionTo($permission);

        return $this->respondWithSuccess([
            'message' => 'دسترسی با موفقیت به دبیر اضافه شد.',
            'permission' => [
                'id' => $permission->id,
                'name' => $permission->name,
                'guard_name' => $permission->guard_name,
            ],
        ]);
    }

    /**
     * حذف یک دسترسی مسیرمحور از دبیر
     */
    public function revokeUserPermission(Request $request, User $user): JsonResponse
    {
        if (!$user->hasRole(UserRole::TEACHER->value)) {
            return $this->respondWithError(
                'فقط برای دبیر می‌توان دسترسی مسیرمحور تعریف کرد.',
                null,
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $actions = collect(PermissionAction::cases())
            ->pluck('value')
            ->all();

        $validated = $request->validate([
            'action' => [
                'required',
                'string',
                Rule::in($actions),
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

        $permissionName = $this->permissionService->getPermissionName(
            PermissionAction::from($validated['action']),
            $validated['season'],
            $validated['lesson'] ?? null,
            $validated['grade'] ?? null
        );

        $permission = Permission::where('name', $permissionName)
            ->where('guard_name', 'web')
            ->first();

        if (!$permission) {
            return $this->respondWithError(
                'این دسترسی وجود ندارد.',
                null,
                Response::HTTP_NOT_FOUND
            );
        }

        $user->revokePermissionTo($permission);

        return $this->respondWithSuccess([
            'message' => 'دسترسی با موفقیت از دبیر حذف شد.',
            'permission' => $permissionName,
        ]);
    }
}
