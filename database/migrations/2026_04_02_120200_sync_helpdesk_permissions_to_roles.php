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

        $permissions = [
            'view helpdesk tickets',
            'create helpdesk tickets',
            'manage helpdesk tickets',
        ];

        foreach ($permissions as $permissionName) {
            Permission::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
        }

        foreach (['Employee', 'IT Staff', 'Operational Director', 'Accounting', 'Admin'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->first();

            if (!$role) {
                continue;
            }

            $role->givePermissionTo(['view helpdesk tickets', 'create helpdesk tickets']);

            if (in_array($roleName, ['IT Staff', 'Admin'], true)) {
                $role->givePermissionTo('manage helpdesk tickets');
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

        DB::table('role_has_permissions')
            ->whereIn('permission_id', Permission::query()
                ->whereIn('name', [
                    'view helpdesk tickets',
                    'create helpdesk tickets',
                    'manage helpdesk tickets',
                ])
                ->pluck('id'))
            ->delete();

        Permission::query()
            ->whereIn('name', [
                'view helpdesk tickets',
                'create helpdesk tickets',
                'manage helpdesk tickets',
            ])
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
