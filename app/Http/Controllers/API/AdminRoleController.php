<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ApprovalStep;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AdminRoleController extends Controller
{
    private const SYSTEM_ROLES = [
        'Admin',
        'Employee',
        'IT Staff',
        'Operational Director',
        'Accounting',
    ];

    /**
     * List roles and permission catalogue for admin management.
     */
    public function index()
    {
        try {
            $roles = Role::query()
                ->with('permissions')
                ->orderByRaw("CASE WHEN name = 'Admin' THEN 0 ELSE 1 END")
                ->orderBy('name')
                ->get()
                ->map(fn (Role $role) => $this->transformRole($role))
                ->values();

            return response()->json([
                'roles' => $roles,
                'permissions' => Permission::query()
                    ->orderBy('name')
                    ->pluck('name')
                    ->values()
                    ->all(),
            ]);
        } catch (ValidationException|HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('List Roles Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create a custom role with selected permissions.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate($this->validationRules());

            $role = DB::transaction(function () use ($validated) {
                $role = Role::query()->create([
                    'name' => trim($validated['name']),
                    'guard_name' => 'web',
                    'is_active' => $validated['is_active'] ?? true,
                ]);

                $role->syncPermissions($validated['permissions'] ?? []);

                return $role->load('permissions');
            });

            $this->forgetPermissionCache();

            return response()->json([
                'success' => true,
                'role' => $this->transformRole($role),
            ], 201);
        } catch (ValidationException|HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Create Role Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update role metadata and permission assignments.
     */
    public function update(Request $request, int $id)
    {
        try {
            /** @var Role $role */
            $role = Role::query()->with('permissions')->findOrFail($id);
            $validated = $request->validate($this->validationRules($role));
            $oldName = $role->name;
            $newName = array_key_exists('name', $validated) ? trim($validated['name']) : $role->name;
            $nextIsActive = array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : (bool) $role->is_active;

            $this->guardRoleMutation($role, $oldName, $newName, $nextIsActive);

            DB::transaction(function () use ($role, $validated, $oldName, $newName, $nextIsActive) {
                $role->fill([
                    'name' => $newName,
                    'is_active' => $nextIsActive,
                ]);
                $role->save();

                if (array_key_exists('permissions', $validated)) {
                    $role->syncPermissions($validated['permissions'] ?? []);
                }

                if ($oldName !== $newName) {
                    $this->syncRoleNameReferences($oldName, $newName);
                }
            });

            $this->forgetPermissionCache();
            $role->refresh()->load('permissions');

            return response()->json([
                'success' => true,
                'role' => $this->transformRole($role),
            ]);
        } catch (ValidationException|HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Update Role Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a role when it is safe to remove permanently.
     */
    public function destroy(int $id)
    {
        try {
            /** @var Role $role */
            $role = Role::query()->with('permissions')->findOrFail($id);
            $usage = $this->roleUsage($role->name);

            if ($this->isSystemRole($role->name)) {
                throw new HttpResponseException(response()->json([
                    'error' => 'Role sistem tidak bisa dihapus.',
                ], 422));
            }

            if ($usage['users_count'] > 0 || $usage['workflows_count'] > 0 || $usage['pending_approvals_count'] > 0) {
                throw new HttpResponseException(response()->json([
                    'error' => 'Role masih dipakai user, workflow, atau approval aktif sehingga belum bisa dihapus.',
                ], 422));
            }

            $role->delete();
            $this->forgetPermissionCache();

            return response()->json([
                'success' => true,
                'message' => 'Role berhasil dihapus.',
            ]);
        } catch (ValidationException|HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Delete Role Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function validationRules(?Role $role = null): array
    {
        $roleId = $role?->id;
        $isUpdate = $role !== null;

        return [
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($roleId)->where('guard_name', 'web')],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function transformRole(Role $role): array
    {
        $role->loadMissing('permissions');
        $usage = $this->roleUsage($role->name);
        $isSystem = $this->isSystemRole($role->name);

        return [
            'id' => $role->id,
            'name' => $role->name,
            'is_active' => (bool) $role->is_active,
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
            'users_count' => $usage['users_count'],
            'workflows_count' => $usage['workflows_count'],
            'pending_approvals_count' => $usage['pending_approvals_count'],
            'is_system' => $isSystem,
            'can_edit_name' => !$isSystem,
            'can_toggle_active' => !$isSystem,
            'can_delete' => !$isSystem,
            'created_at' => optional($role->created_at)?->toISOString(),
            'updated_at' => optional($role->updated_at)?->toISOString(),
        ];
    }

    private function roleUsage(string $roleName): array
    {
        return [
            'users_count' => User::query()->role($roleName)->count(),
            'workflows_count' => Workflow::query()
                ->get()
                ->filter(function (Workflow $workflow) use ($roleName) {
                    foreach (($workflow->workflow_config['steps'] ?? []) as $step) {
                        if (($step['role'] ?? null) === $roleName) {
                            return true;
                        }

                        if (($step['actor_type'] ?? null) === 'role' && ($step['actor_value'] ?? null) === $roleName) {
                            return true;
                        }
                    }

                    return false;
                })
                ->count(),
            'pending_approvals_count' => ApprovalStep::query()
                ->where('approver_role', $roleName)
                ->where('status', 'pending')
                ->count(),
        ];
    }

    private function isSystemRole(string $roleName): bool
    {
        return in_array($roleName, self::SYSTEM_ROLES, true);
    }

    private function guardRoleMutation(Role $role, string $oldName, string $newName, bool $nextIsActive): void
    {
        if ($this->isSystemRole($oldName)) {
            if ($oldName !== $newName) {
                throw new HttpResponseException(response()->json([
                    'error' => 'Nama role sistem tidak bisa diubah.',
                ], 422));
            }

            if (!$nextIsActive) {
                throw new HttpResponseException(response()->json([
                    'error' => 'Role sistem tidak bisa dinonaktifkan.',
                ], 422));
            }

            return;
        }

        if (!$nextIsActive && $role->is_active) {
            $usage = $this->roleUsage($oldName);

            if ($usage['users_count'] > 0 || $usage['workflows_count'] > 0 || $usage['pending_approvals_count'] > 0) {
                throw new HttpResponseException(response()->json([
                    'error' => 'Role masih dipakai user, workflow, atau approval aktif sehingga belum bisa dinonaktifkan.',
                ], 422));
            }
        }
    }

    private function syncRoleNameReferences(string $oldName, string $newName): void
    {
        ApprovalStep::query()
            ->where('approver_role', $oldName)
            ->update([
                'approver_role' => $newName,
            ]);

        Workflow::query()
            ->get()
            ->each(function (Workflow $workflow) use ($oldName, $newName) {
                $config = $workflow->workflow_config ?? [];
                $steps = $config['steps'] ?? [];
                $changed = false;

                foreach ($steps as $index => $step) {
                    if (($step['role'] ?? null) === $oldName) {
                        $steps[$index]['role'] = $newName;
                        $changed = true;
                    }

                    if (($step['actor_type'] ?? null) === 'role' && ($step['actor_value'] ?? null) === $oldName) {
                        $steps[$index]['actor_value'] = $newName;
                        $steps[$index]['actor_label'] = $newName;
                        $changed = true;
                    }
                }

                if (!$changed) {
                    return;
                }

                $config['steps'] = $steps;
                $workflow->workflow_config = $config;
                $workflow->save();
            });
    }

    private function forgetPermissionCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
