<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'view knowledge hub',
            'manage knowledge hub',
        ] as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
        }

        foreach (['Employee', 'IT Staff', 'Operational Director', 'Accounting', 'Admin'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

            if (! $role) {
                continue;
            }

            $role->givePermissionTo('view knowledge hub');

            if ($roleName === 'Admin') {
                $role->givePermissionTo('manage knowledge hub');
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = Permission::query()
            ->whereIn('name', ['view knowledge hub', 'manage knowledge hub'])
            ->pluck('id');

        if ($permissions->isNotEmpty()) {
            DB::table('role_has_permissions')
                ->whereIn('permission_id', $permissions)
                ->delete();

            DB::table('model_has_permissions')
                ->whereIn('permission_id', $permissions)
                ->delete();
        }

        Permission::query()->whereIn('name', ['view knowledge hub', 'manage knowledge hub'])->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
