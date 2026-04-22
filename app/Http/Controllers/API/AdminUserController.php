<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PortalRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class AdminUserController extends Controller
{
    public function __construct(private readonly PortalRegistry $portalRegistry) {}

    /**
     * List active and inactive users for admin management.
     */
    public function index(Request $request)
    {
        try {
            $query = User::query()
                ->with('roles')
                ->orderByDesc('is_active')
                ->orderBy('name');

            if ($request->filled('search')) {
                $search = trim((string) $request->string('search')->value());

                $query->where(function ($userQuery) use ($search) {
                    $userQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('department', 'like', "%{$search}%")
                        ->orWhere('employee_id', 'like', "%{$search}%")
                        ->orWhere('s21plus_user_id', 'like', "%{$search}%");
                });
            }

            if ($request->filled('status')) {
                $status = $request->string('status')->value();

                if (in_array($status, ['active', 'inactive'], true)) {
                    $query->where('is_active', $status === 'active');
                }
            }

            if ($request->filled('role')) {
                $role = $request->string('role')->value();
                $query->role($role);
            }

            $users = $query->get()->map(fn (User $user) => $this->transformUser($user))->values();

            return response()->json([
                'users' => $users,
                'roles' => $this->availableRoles(),
                'app_catalog' => $this->portalRegistry->appCatalog(),
            ]);
        } catch (ValidationException|HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('List Users Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create a new internal user.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate($this->validationRules());

            $user = DB::transaction(function () use ($validated) {
                [$allowedApps, $homeApp] = $this->resolvePortalAccess($validated);

                $user = User::create([
                    'name' => trim($validated['name']),
                    'email' => trim($validated['email']),
                    'password' => Hash::make($validated['password']),
                    'department' => $this->nullableTrim($validated['department'] ?? null),
                    'employee_id' => $this->nullableTrim($validated['employee_id'] ?? null),
                    's21plus_user_id' => $this->nullableTrim($validated['s21plus_user_id'] ?? null),
                    'phone_number' => $this->nullableTrim($validated['phone_number'] ?? null),
                    'is_active' => $validated['is_active'] ?? true,
                    'allowed_apps' => $allowedApps,
                    'home_app' => $homeApp,
                ]);

                $user->syncRoles($validated['roles']);

                return $user->load('roles');
            });

            return response()->json([
                'success' => true,
                'user' => $this->transformUser($user),
            ], 201);
        } catch (ValidationException|HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Create User Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update an existing internal user.
     */
    public function update(Request $request, int $id)
    {
        try {
            $user = User::query()->with('roles')->findOrFail($id);
            $validated = $request->validate($this->validationRules($user));

            $roles = $validated['roles'] ?? $user->roles->pluck('name')->all();
            $isActive = array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : (bool) $user->is_active;
            [$allowedApps, $homeApp] = $this->resolvePortalAccess($validated, $user);

            $this->guardCriticalAdminMutation($user, $roles, $isActive);

            DB::transaction(function () use ($user, $validated, $roles, $isActive, $allowedApps, $homeApp) {
                $user->fill([
                    'name' => array_key_exists('name', $validated) ? trim($validated['name']) : $user->name,
                    'email' => array_key_exists('email', $validated) ? trim($validated['email']) : $user->email,
                    'department' => array_key_exists('department', $validated) ? $this->nullableTrim($validated['department']) : $user->department,
                    'employee_id' => array_key_exists('employee_id', $validated) ? $this->nullableTrim($validated['employee_id']) : $user->employee_id,
                    's21plus_user_id' => array_key_exists('s21plus_user_id', $validated) ? $this->nullableTrim($validated['s21plus_user_id']) : $user->s21plus_user_id,
                    'phone_number' => array_key_exists('phone_number', $validated) ? $this->nullableTrim($validated['phone_number']) : $user->phone_number,
                    'is_active' => $isActive,
                    'allowed_apps' => $allowedApps,
                    'home_app' => $homeApp,
                ]);

                if (!empty($validated['password'])) {
                    $user->password = Hash::make($validated['password']);
                }

                $user->save();
                $user->syncRoles($roles);

                if (!$user->is_active) {
                    $this->revokeUserSessions($user);
                }
            });

            $user->refresh()->load('roles');

            return response()->json([
                'success' => true,
                'user' => $this->transformUser($user),
            ]);
        } catch (ValidationException|HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Update User Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Archive a user using soft delete.
     */
    public function destroy(int $id)
    {
        try {
            $user = User::query()->with('roles')->findOrFail($id);
            $this->guardCriticalAdminDeletion($user);

            DB::transaction(function () use ($user) {
                $this->revokeUserSessions($user);
                $user->delete();
            });

            return response()->json([
                'success' => true,
                'message' => 'User berhasil diarsipkan.',
            ]);
        } catch (ValidationException|HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Delete User Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function validationRules(?User $user = null): array
    {
        $userId = $user?->id;
        $isUpdate = $user !== null;

        return [
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'email' => [$isUpdate ? 'sometimes' : 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'department' => ['sometimes', 'nullable', 'string', 'max:255'],
            'employee_id' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('users', 'employee_id')->ignore($userId)],
            's21plus_user_id' => ['sometimes', 'nullable', 'string', 'max:120', Rule::unique('users', 's21plus_user_id')->ignore($userId)],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'allowed_apps' => ['sometimes', 'array', 'min:1'],
            'allowed_apps.*' => ['sometimes', 'string', Rule::in($this->portalRegistry->appKeys())],
            'home_app' => ['sometimes', 'nullable', 'string', Rule::in($this->portalRegistry->appKeys())],
            'roles' => [$isUpdate ? 'sometimes' : 'required', 'array', 'min:1'],
            'roles.*' => ['sometimes', 'string', Rule::exists('roles', 'name')],
            'is_active' => ['sometimes', 'boolean'],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => [$user ? 'nullable' : 'required_with:password', 'string', 'min:8'],
        ];
    }

    private function transformUser(User $user): array
    {
        $user->loadMissing('roles');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'department' => $user->department,
            'employee_id' => $user->employee_id,
            's21plus_user_id' => $user->s21plus_user_id,
            'phone_number' => $user->phone_number,
            'is_active' => (bool) $user->is_active,
            'allowed_apps' => $this->portalRegistry->allowedAppsFor($user),
            'home_app' => $this->portalRegistry->homeAppFor($user),
            'roles' => $user->roles->pluck('name')->values()->all(),
            'primary_role' => $user->roles->pluck('name')->first(),
            'is_current_user' => (int) $user->id === (int) auth()->id(),
            'created_at' => optional($user->created_at)?->toISOString(),
            'updated_at' => optional($user->updated_at)?->toISOString(),
        ];
    }

    private function availableRoles(): array
    {
        return Role::query()
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN name = 'Admin' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->pluck('name')
            ->values()
            ->all();
    }

    private function resolvePortalAccess(array $validated, ?User $user = null): array
    {
        $email = array_key_exists('email', $validated)
            ? trim((string) $validated['email'])
            : $user?->email;

        $allowedApps = array_key_exists('allowed_apps', $validated)
            ? $this->portalRegistry->normalizeAllowedApps($validated['allowed_apps'], $email)
            : ($user ? $this->portalRegistry->allowedAppsFor($user) : $this->portalRegistry->normalizeAllowedApps(null, $email));

        $requestedHomeApp = array_key_exists('home_app', $validated)
            ? $this->nullableTrim($validated['home_app'])
            : ($user?->home_app);

        if ($requestedHomeApp !== null && ! in_array($requestedHomeApp, $allowedApps, true)) {
            throw new HttpResponseException(response()->json([
                'errors' => [
                    'home_app' => ['Default landing harus termasuk dalam akses aplikasi user.'],
                ],
            ], 422));
        }

        return [
            $allowedApps,
            $this->portalRegistry->resolveHomeApp($requestedHomeApp, $allowedApps, $email),
        ];
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function guardCriticalAdminMutation(User $user, array $roles, bool $isActive): void
    {
        $currentUser = auth()->user();
        $willRemainAdmin = in_array('Admin', $roles, true);

        if ((int) $user->id === (int) $currentUser->id) {
            if (!$isActive) {
                throw new HttpResponseException(response()->json([
                    'error' => 'Akun admin yang sedang digunakan tidak bisa dinonaktifkan.',
                ], 422));
            }

            if (!$willRemainAdmin) {
                throw new HttpResponseException(response()->json([
                    'error' => 'Anda tidak bisa melepas role Admin dari akun yang sedang digunakan.',
                ], 422));
            }
        }

        if ($user->hasRole('Admin') && $user->is_active && (!$willRemainAdmin || !$isActive) && $this->activeAdminCount() <= 1) {
            throw new HttpResponseException(response()->json([
                'error' => 'Sistem harus memiliki minimal satu admin aktif.',
            ], 422));
        }
    }

    private function guardCriticalAdminDeletion(User $user): void
    {
        $currentUser = auth()->user();

        if ((int) $user->id === (int) $currentUser->id) {
            throw new HttpResponseException(response()->json([
                'error' => 'Akun admin yang sedang digunakan tidak bisa dihapus.',
            ], 422));
        }

        if ($user->hasRole('Admin') && $user->is_active && $this->activeAdminCount() <= 1) {
            throw new HttpResponseException(response()->json([
                'error' => 'Sistem harus memiliki minimal satu admin aktif.',
            ], 422));
        }
    }

    private function activeAdminCount(): int
    {
        return User::query()
            ->role('Admin')
            ->where('is_active', true)
            ->count();
    }

    private function revokeUserSessions(User $user): void
    {
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->delete();
    }
}
