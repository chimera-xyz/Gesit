<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Create roles
        $roles = [
            [
                'name' => 'Employee',
                'guard_name' => 'web',
            ],
            [
                'name' => 'IT Staff',
                'guard_name' => 'web',
            ],
            [
                'name' => 'Operational Director',
                'guard_name' => 'web',
            ],
            [
                'name' => 'Accounting',
                'guard_name' => 'web',
            ],
            [
                'name' => 'Admin',
                'guard_name' => 'web',
            ],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role['name'], 'guard_name' => $role['guard_name']], $role);
        }

        // Create permissions
        $permissions = [
            ['name' => 'view forms', 'guard_name' => 'web'],
            ['name' => 'create forms', 'guard_name' => 'web'],
            ['name' => 'edit forms', 'guard_name' => 'web'],
            ['name' => 'delete forms', 'guard_name' => 'web'],
            ['name' => 'submit forms', 'guard_name' => 'web'],
            ['name' => 'approve forms', 'guard_name' => 'web'],
            ['name' => 'reject forms', 'guard_name' => 'web'],
            ['name' => 'view submissions', 'guard_name' => 'web'],
            ['name' => 'create signatures', 'guard_name' => 'web'],
            ['name' => 'manage users', 'guard_name' => 'web'],
            ['name' => 'manage workflows', 'guard_name' => 'web'],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission['name'], 'guard_name' => $permission['guard_name']], $permission);
        }

        // Assign permissions to roles
        $adminRole = Role::where('name', 'Admin')->first();
        $adminRole->syncPermissions(Permission::all());

        $basePermissions = ['view forms', 'submit forms', 'view submissions'];

        $itRole = Role::where('name', 'IT Staff')->first();
        $itRole->syncPermissions([
            ...$basePermissions,
            'approve forms',
            'reject forms',
            'create signatures',
        ]);

        $directorRole = Role::where('name', 'Operational Director')->first();
        $directorRole->syncPermissions([
            ...$basePermissions,
            'approve forms',
            'reject forms',
            'create signatures',
        ]);

        $accountingRole = Role::where('name', 'Accounting')->first();
        $accountingRole->syncPermissions([
            ...$basePermissions,
            'approve forms',
            'reject forms',
            'create signatures',
        ]);

        $employeeRole = Role::where('name', 'Employee')->first();
        $employeeRole->syncPermissions($basePermissions);
    }
}
