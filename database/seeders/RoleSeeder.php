<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::query()->firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);

        $adminUser = User::query()->where('phone', '+923001234567')->first();

        if ($adminUser && ! $adminUser->hasRole('admin')) {
            $adminUser->assignRole($adminRole);
            $this->command?->info('Assigned admin role to +923001234567');
        }
    }
}
