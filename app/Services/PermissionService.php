<?php

namespace App\Services;

use App\Models\User;
use App\Enums\UserRole;
use App\Enums\PermissionAction;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class PermissionService
{
    /**
     * کاراکتر جداکننده بخش‌های پرمیشن
     * پیشنهاد: کاراکتری که در نام درس یا پایه استفاده نمی‌شود
     */
    protected string $delimiter = '#';

    /**
     * ساخت نام پرمیشن بر اساس اجزا
     *
     * @param string $action نوع عملیات (read, write, delete, ...)
     * @param string $season فصل (مثلا: 1404-04)
     * @param string|null $lesson نام درس (مثلا: ریاضی)
     * @param string|null $grade نام پایه (مثلا: ششم)
     * @return string
     */
    public function getPermissionName(PermissionAction $action, string $season, ?string $lesson = null, ?string $grade = null): string
    {
        // تبدیل Enum به مقدار رشته‌ای و ساخت نام پرمیشن
        // مثال: write#1404-04#ریاضی#ششم
        $parts = array_filter([$action->value, $season, $lesson, $grade]);
        return implode($this->delimiter, $parts);
    }

    /**
     * متد کمکی برای تطبیق الگو
     * بررسی می‌کند آیا کاربر پرمیشنی دارد که با الگوی ساخته شده شروع شود؟
     */
    private function matchesPattern(User $user, PermissionAction $action, string $season, ?string $lesson = null): bool
    {
//        \Log::info('FTP file renamed', [
//            '$user' => $user,
//            '$action' => $action,
//            '$season' => $season,
//            '$lesson' => $lesson,
//        ]);

        // ساخت الگوی پایه (مثلا: read#1404-04# یا read#1404-04#ریاضی#)
        // از getPermissionName استفاده می‌کنیم تا ساختار یکسان باشد
        $basePattern = $this->getPermissionName($action, $season, $lesson);

        // اضافه کردن جداکننده به انتها برای اطمینان از تطبیق کامل بخش‌ها
        // مثلا اگر الگو "read#1404-04#" است، نباید "read#1404-04-extra#" را قبول کند
        $searchPattern = $basePattern . $this->delimiter;

        $permissions = $user->permissions->pluck('name');

        foreach ($permissions as $permission) {
            // اگر پرمیشن کاربر دقیقاً با الگو برابر است (مثلا دسترسی به خود فصل)
            if ($permission === $basePattern) {
                return true;
            }
            // اگر با الگو شروع می‌شود (مثلا دسترسی به زیرمجموعه‌های فصل)
            if (str_starts_with($permission, $searchPattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * بررسی دسترسی کاربر به یک مسیر خاص
     *
     * @param User $user کاربر مورد نظر
     * @param PermissionAction $action اکشن (read/write)
     * @param string $path مسیر درخواستی
     * @return bool
     */
    public function checkAccess(User $user, PermissionAction $action, string $path): bool
    {
        // ۱. ادمین دسترسی کامل دارد
        if ($user->hasRole(UserRole::ADMIN->value)) {
            return true;
        }

        // ۲. پشتیبان فقط دسترسی خواندن دارد
        if ($user->hasRole(UserRole::SUPPORT->value)) {
            return $action === PermissionAction::READ;
        }

        // ۳. دبیران: بررسی پرمیشن‌های دقیق
        if ($user->hasRole(UserRole::TEACHER->value)) {
            return $this->checkTeacherAccess($user, $action, $path);
        }

        return false;
    }

    /**
     * فیلتر کردن دایرکتوری‌های یک مسیر بر اساس دسترسی‌های دبیر
     */
    public function filterDirectoriesForTeacher(array $directories, User $user, PermissionAction $action, string $currentPath): array
    {
        $currentPath = $this->normalizeBrowsePath($currentPath);

        $filtered = [];

        foreach ($directories as $dir) {
            // ساخت مسیر کامل بدون اسلش اضافی
            // "1403-01" برای فصل در ریشه / "1403-01/ریاضی" برای درس
            $fullPath = trim(trim($currentPath, '/') . '/' . $dir['name'], '/');

            // اگر در ریشه است، فقط نام فصل را داریم (بدون هیچ اسلش)
            $isSeason = substr_count($fullPath, '/') === 0;

            if ($isSeason) {
                // چک کن آیا پرمیشنی با این فصل وجود دارد؟
                // مثلا: read#1403-01#
                $prefix = $action->value . $this->delimiter . $fullPath . $this->delimiter;

                $allowed = $user->permissions
                    ->pluck('name')
                    ->contains(fn ($permission) => str_starts_with($permission, $prefix));
            } else {
                // درس/پایه: مسیر کامل را با پیشوند / چک کن
                $allowed = $this->checkAccess($user, $action, '/' . $fullPath);
            }

            if ($allowed) {
                $filtered[] = $dir;
            }
        }

        return $filtered;
    }

    /**
     * فیلتر کردن ساختار درختی بر اساس پرمیشن‌های دبیر
     */
    public function filterTreeForTeacher(array $tree, $user): array
    {
        $filtered = [];

        foreach ($tree as $season => $lessons) {
            foreach ($lessons as $lesson => $grades) {
                foreach ($grades as $grade) {
                    // بررسی پرمیشن خواندن برای این مسیر خاص
                    // از PermissionService استفاده می‌کنیم
                    $path = $this->normalizeBrowsePath("{$season}/{$lesson}/{$grade}");

                    if ($this->checkAccess($user, PermissionAction::READ, $path)) {
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

    public function normalizeBrowsePath(?string $path): string
    {
        $path = trim((string) $path);

        if ($path === '' || $path === '/') {
            return '/';
        }

        return '/' . trim($path, '/');
    }

    /**
     * بررسی دسترسی دبیر با بررسی دقیق فصل، درس و پایه
     */
    private function checkTeacherAccess(User $user, PermissionAction $action, string $path): bool
    {
        // جدا کردن اجزای مسیر
        $segments = array_values(array_filter(explode('/', '/' . trim($path, '/'))));

        $season = $segments[0] ?? null;
        $lesson = $segments[1] ?? null;
        $grade = $segments[2] ?? null;

        // حالت ۱: کاربر در روت است (/)
        if (empty($season)) {
            // آیا حداقل یک پرمیشن از جنس این action دارد؟
            $prefix = $action->value . $this->delimiter; // مثلا "read#"

            return $user->permissions
                ->pluck('name')
                ->contains(fn ($permission) => str_starts_with($permission, $prefix));
        }

        // حالت ۲: کاربر فقط فصل را انتخاب کرده (مثلا: /1404-04)
        // باید چک کنیم آیا اصلا دسترسی به این فصل را دارد؟
        // ما باید ببینیم آیا پرمیشنی با این فصل (و هر درس و پایه‌ای) برای او وجود دارد؟
        if (empty($lesson)) {
            return $this->matchesPattern($user, $action, $season);
        }

        // حالت ۳: کاربر درس را انتخاب کرده (مثلا: /1404-04/ریاضی)
        // باید چک کنیم آیا دسترسی به این درس در این فصل را دارد؟
        if (empty($grade)) {
            return $this->matchesPattern($user, $action, $season, $lesson);
        }

        // حالت ۴: کاربر پایه را هم انتخاب کرده (مثلا: /1404-04/ریاضی/ششم)
        // بررسی پرمیشن دقیق
        $permissionName = $this->getPermissionName($action, $season, $lesson, $grade);

        return $this->userHasPermission($user, $permissionName);

    }

    private function userHasPermission(User $user, string $permissionName): bool
    {
        try {
            return $user->hasPermissionTo($permissionName, 'web');
        } catch (PermissionDoesNotExist $e) {
            \Log::warning('Permission does not exist while checking access.', [
                'user_id' => $user->id,
                'permission' => $permissionName,
                'guard' => 'web',
            ]);

            return false;
        }
    }
}
