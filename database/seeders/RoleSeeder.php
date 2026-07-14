<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'access admin dashboard',
            'view system logs',
            'access user home',
            'manage users',
            'manage storage plans',
        ];

        foreach ($permissions as $name) {
            Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $superAdmin = Role::query()->firstOrCreate([
            'name' => 'super_admin',
            'guard_name' => 'web',
        ]);
        $admin = Role::query()->firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);
        $user = Role::query()->firstOrCreate([
            'name' => 'user',
            'guard_name' => 'web',
        ]);

        $superAdmin->syncPermissions(Permission::query()->where('guard_name', 'web')->get());
        $admin->syncPermissions([
            'access admin dashboard',
            'view system logs',
            'manage users',
            'manage storage plans',
        ]);
        $user->syncPermissions([
            'access user home',
        ]);

        $adminUser = User::query()->where('phone', '+923001234567')->first();
        if ($adminUser && ! $adminUser->hasRole('admin') && ! $adminUser->hasRole('super_admin')) {
            $adminUser->assignRole($admin);
            $this->command?->info('Assigned admin role to +923001234567');
        }

        $superAdminUser = User::query()->where('phone', '+923009999999')->first();
        if ($superAdminUser && ! $superAdminUser->hasRole('super_admin')) {
            $superAdminUser->syncRoles([$superAdmin]);
            $this->command?->info('Assigned super_admin role to +923009999999');
        }

        User::query()
            ->whereDoesntHave('roles')
            ->each(function (User $member) use ($user): void {
                $member->assignRole($user);
            });
    }
}
