<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_create_user_with_multiple_roles(): void
    {
        $admin = $this->makeUserWithRole('Admin');

        $response = $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'Rania Finance IT',
            'email' => 'rania@example.com',
            'department' => 'Finance',
            'employee_id' => 'EMP-900',
            's21plus_user_id' => 'rania.s21',
            'phone_number' => '08123456789',
            'roles' => ['Accounting', 'IT Staff'],
            'is_active' => true,
            'password' => 'securepass123',
            'password_confirmation' => 'securepass123',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.name', 'Rania Finance IT')
            ->assertJsonPath('user.department', 'Finance')
            ->assertJsonPath('user.s21plus_user_id', 'rania.s21');

        $this->assertEqualsCanonicalizing(
            ['Accounting', 'IT Staff'],
            $response->json('user.roles')
        );

        $user = User::query()->where('email', 'rania@example.com')->firstOrFail();

        $this->assertTrue($user->hasRole('Accounting'));
        $this->assertTrue($user->hasRole('IT Staff'));
        $this->assertTrue(Hash::check('securepass123', $user->password));
        $this->assertSame('rania.s21', $user->s21plus_user_id);
    }

    public function test_admin_can_update_user_and_toggle_status(): void
    {
        $admin = $this->makeUserWithRole('Admin');
        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Old Name',
            'email' => 'employee@example.com',
            'department' => 'GA',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->putJson("/api/users/{$user->id}", [
            'name' => 'Updated User',
            'department' => 'IT Operations',
            's21plus_user_id' => 'updated.s21',
            'roles' => ['IT Staff', 'Employee'],
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Updated User')
            ->assertJsonPath('user.is_active', false)
            ->assertJsonPath('user.s21plus_user_id', 'updated.s21');

        $user->refresh();

        $this->assertSame('Updated User', $user->name);
        $this->assertSame('IT Operations', $user->department);
        $this->assertSame('updated.s21', $user->s21plus_user_id);
        $this->assertFalse($user->is_active);
        $this->assertTrue($user->hasRole('IT Staff'));
        $this->assertTrue($user->hasRole('Employee'));
    }

    public function test_admin_can_soft_delete_user_but_not_self(): void
    {
        $admin = $this->makeUserWithRole('Admin', [
            'email' => 'admin@example.com',
        ]);
        $user = $this->makeUserWithRole('Employee', [
            'email' => 'archive@example.com',
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('users', [
            'id' => $user->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/users/{$admin->id}")
            ->assertStatus(422);
    }

    public function test_last_active_admin_cannot_remove_admin_role_or_be_archived(): void
    {
        $admin = $this->makeUserWithRole('Admin', [
            'email' => 'solo-admin@example.com',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/users/{$admin->id}", [
                'roles' => ['Employee'],
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->deleteJson("/api/users/{$admin->id}")
            ->assertStatus(422);
    }

    public function test_inactive_user_cannot_login_or_access_authenticated_routes(): void
    {
        $inactiveUser = $this->makeUserWithRole('Employee', [
            'email' => 'inactive@example.com',
            'password' => bcrypt('password123'),
            'is_active' => false,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'inactive@example.com',
            'password' => 'password123',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error', 'Account disabled');

        $this->actingAs($inactiveUser)
            ->getJson('/api/user')
            ->assertStatus(403)
            ->assertJsonPath('error', 'Account disabled');
    }

    private function makeUserWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }
}
