<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Enums\DefaultPermission;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (DefaultPermission::cases() as $permission) {
            Permission::firstOrCreate([
                'name' => $permission->value,
                'guard_name' => 'web'
            ]);
        }

        $this->command->info('Permissions seeded successfully.');
    }
}
