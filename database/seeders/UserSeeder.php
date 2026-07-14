<?php

namespace Database\Seeders;

use App\Models\User;
use App\Enums\UserRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Retrieve roles
        $adminRole = Role::findByName(UserRole::ADMIN->value);
        $teacherRole = Role::findByName(UserRole::TEACHER->value);

        // Create Admin User
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'firstname' => 'Admin',
                'lastname' => 'User',
                'username' => 'admin',
                'password' => bcrypt('password'), // رمز: password
                'mobile' => '09120000000',
            ]
        );
        $admin->assignRole($adminRole);

        $this->command->info('Users seeded successfully.');
    }
}
