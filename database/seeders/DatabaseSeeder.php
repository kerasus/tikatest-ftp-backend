<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Color;
use App\Models\Fabric;
use App\Models\Production;
use App\Models\ProductPart;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Disable foreign key checks during seeding
        \DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // Truncate tables to ensure clean state
        User::truncate();

        // پاک کردن جداول مربوط به پرمیشن و رول (Spatie)
        \DB::table('model_has_permissions')->truncate();
        \DB::table('model_has_roles')->truncate();
        \DB::table('role_has_permissions')->truncate();
        \DB::table('permissions')->truncate();
        \DB::table('roles')->truncate();

        // Re-enable foreign key checks
        \DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            UserSeeder::class,
        ]);
    }
}
