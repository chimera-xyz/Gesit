<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\User;
use App\Models\Workflow;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_create_custom_role_with_permissions(): void
    {
        $admin = $this->makeUserWithRole('Admin');

        $response = $this->actingAs($admin)->postJson('/api/roles', [
            'name' => 'Procurement Reviewer',
            'permissions' => ['view forms', 'view submissions', 'approve forms'],
            'is_active' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('role.name', 'Procurement Reviewer')
            ->assertJsonPath('role.is_active', true);

        $this->assertEqualsCanonicalizing(
            ['view forms', 'view submissions', 'approve forms'],
            $response->json('role.permissions')
        );

        $role = Role::query()->where('name', 'Procurement Reviewer')->firstOrFail();
        $this->assertTrue($role->hasPermissionTo('approve forms'));
    }

    public function test_admin_can_rename_custom_role_and_sync_workflow_and_approval_steps(): void
    {
        $admin = $this->makeUserWithRole('Admin');
        $role = Role::query()->create([
            'name' => 'Procurement Reviewer',
            'guard_name' => 'web',
            'is_active' => true,
        ]);
        $role->syncPermissions(['view submissions']);

        $workflow = Workflow::query()->create([
            'name' => 'Custom Workflow',
            'slug' => 'custom-workflow',
            'description' => 'Workflow role sync test',
            'workflow_config' => [
                'steps' => [
                    [
                        'step_number' => 1,
                        'name' => 'Custom Review',
                        'role' => 'Procurement Reviewer',
                        'action' => 'review',
                        'status' => 'pending_custom',
                    ],
                ],
                'statuses' => ['pending_custom'],
            ],
            'is_active' => true,
        ]);

        $form = Form::query()->create([
            'name' => 'Custom Form',
            'slug' => 'custom-form',
            'description' => 'Custom form for role rename test',
            'form_config' => [
                'fields' => [
                    [
                        'id' => 'notes',
                        'type' => 'textarea',
                        'label' => 'Notes',
                        'required' => false,
                    ],
                ],
            ],
            'workflow_id' => $workflow->id,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $submission = FormSubmission::query()->create([
            'form_id' => $form->id,
            'user_id' => $admin->id,
            'form_data' => [],
            'form_snapshot' => $form->form_config,
            'current_status' => 'pending_custom',
            'current_step' => 1,
            'created_by' => $admin->id,
        ]);

        ApprovalStep::query()->create([
            'form_submission_id' => $submission->id,
            'step_number' => 1,
            'step_name' => 'Custom Review',
            'approver_role' => 'Procurement Reviewer',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->putJson("/api/roles/{$role->id}", [
            'name' => 'Vendor Reviewer',
            'permissions' => ['view submissions', 'approve forms'],
        ])
            ->assertOk()
            ->assertJsonPath('role.name', 'Vendor Reviewer');

        $workflow->refresh();
        $this->assertSame('Vendor Reviewer', $workflow->workflow_config['steps'][0]['role']);

        $this->assertDatabaseHas('approval_steps', [
            'form_submission_id' => $submission->id,
            'approver_role' => 'Vendor Reviewer',
        ]);
    }

    public function test_custom_role_cannot_be_deactivated_or_deleted_when_still_in_use(): void
    {
        $admin = $this->makeUserWithRole('Admin');
        $role = Role::query()->create([
            'name' => 'Helpdesk Reviewer',
            'guard_name' => 'web',
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        $user->assignRole('Helpdesk Reviewer');

        $this->actingAs($admin)
            ->putJson("/api/roles/{$role->id}", [
                'is_active' => false,
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->deleteJson("/api/roles/{$role->id}")
            ->assertStatus(422);
    }

    public function test_system_role_can_update_permissions_but_cannot_be_renamed_or_disabled(): void
    {
        $admin = $this->makeUserWithRole('Admin');
        $employeeRole = Role::query()->where('name', 'Employee')->firstOrFail();

        $this->actingAs($admin)
            ->putJson("/api/roles/{$employeeRole->id}", [
                'permissions' => ['view forms', 'submit forms'],
            ])
            ->assertOk()
            ->assertJsonPath('role.name', 'Employee');

        $this->actingAs($admin)
            ->putJson("/api/roles/{$employeeRole->id}", [
                'name' => 'Staff Biasa',
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->putJson("/api/roles/{$employeeRole->id}", [
                'is_active' => false,
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->deleteJson("/api/roles/{$employeeRole->id}")
            ->assertStatus(422);
    }

    private function makeUserWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }
}
