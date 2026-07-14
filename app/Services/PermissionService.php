<?php

namespace App\Services;

use App\Models\User;
use App\Enums\UserRole;
use App\Enums\PermissionAction;
use Illuminate\Support\Facades\Auth;

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
     * بررسی دسترسی دبیر با بررسی دقیق فصل، درس و پایه
     */
    private function checkTeacherAccess(User $user, PermissionAction $action, string $path): bool
    {
        // جدا کردن اجزای مسیر
        $segments = array_filter(explode('/', $path));

        $season = $segments[0] ?? null;
        $lesson = $segments[1] ?? null;
        $grade = $segments[2] ?? null;

        // حالت ۱: کاربر در روت است (/)
        // آیا اجازه ورود به سیستم را دارد؟ (فرض بر بله است)
        if (empty($season)) {
            return true;
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
        return $user->hasPermissionTo($permissionName);
    }

}
