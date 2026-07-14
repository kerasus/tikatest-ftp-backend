<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use Illuminate\Database\Seeder;
use App\Enums\DefaultPermission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // ۱. ادمین: دسترسی کامل به همه پرمیشن‌ها
        $admin = Role::findByName(UserRole::ADMIN->value);
        $admin->givePermissionTo(Permission::all());

        // ۲. پشتیبان: دسترسی خواندن به همه فایل‌ها (فرض بر این است که پرمیشن‌های read را دارد)
        // در اینجا برای سادگی به همه پرمیشن‌های موجود دسترسی می‌دهیم، اما در کنترلر محدودش کردیم
        $support = Role::findByName(UserRole::SUPPORT->value);
        $support->givePermissionTo([
            DefaultPermission::FULL_ACCESS->value,
            DefaultPermission::MANAGE_USERS->value
        ]); // یا پرمیشن‌های خاص

        $this->command->info('Role permissions seeded successfully.');
    }
}
