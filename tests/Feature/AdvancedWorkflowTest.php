<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\User;
use App\Models\Workflow;
use App\Support\WorkflowConfigService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvancedWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_create_advanced_workflow_definition(): void
    {
        $admin = $this->makeUserWithRole('Admin');
        $specificReviewer = $this->makeUserWithRole('Operational Director', [
            'name' => 'Reviewer Spesifik',
        ]);

        $response = $this->actingAs($admin)->postJson('/api/workflows', [
            'name' => 'SOP Approval Fleksibel',
            'description' => 'Workflow dengan actor campuran role dan user spesifik.',
            'workflow_config' => [
                'steps' => [
                    [
                        'name' => 'Pengajuan Dibuat',
                        'actor_type' => 'requester',
                        'action' => 'submit',
                        'entry_status' => 'submitted',
                        'auto_complete' => true,
                    ],
                    [
                        'name' => 'Review Khusus',
                        'step_key' => 'special_review',
                        'actor_type' => 'user',
                        'actor_value' => (string) $specificReviewer->id,
                        'action' => 'approve',
                        'entry_status' => 'pending_special_review',
                        'notes_required' => true,
                        'requires_signature' => false,
                    ],
                    [
                        'name' => 'Selesai',
                        'actor_type' => 'system',
                        'action' => 'complete',
                        'entry_status' => 'completed',
                        'auto_complete' => true,
                    ],
                ],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('workflow.workflow_config.version', 2)
            ->assertJsonPath('workflow.workflow_config.steps.1.actor_type', 'user')
            ->assertJsonPath('workflow.workflow_config.steps.1.actor_value', (string) $specificReviewer->id)
            ->assertJsonPath('workflow.workflow_config.steps.1.actor_label', 'Reviewer Spesifik')
            ->assertJsonPath('workflow.workflow_config.steps.1.notes_required', true);
    }

    public function test_custom_workflow_can_jump_to_specific_step_and_only_assigned_user_can_approve(): void
    {
        $employee = $this->makeUserWithRole('Employee');
        $specificReviewer = $this->makeUserWithRole('Operational Director', [
            'name' => 'Approver Direktur',
        ]);
        $accounting = $this->makeUserWithRole('Accounting');

        $workflow = Workflow::query()->create([
            'name' => 'Workflow Approval Lanjutan',
            'slug' => 'workflow-approval-lanjutan',
            'description' => 'Menguji next step override dan approver user spesifik.',
            'workflow_config' => app(WorkflowConfigService::class)->normalizeForStorage([
                'steps' => [
                    [
                        'step_key' => 'submit_request',
                        'name' => 'Pengajuan Dibuat',
                        'actor_type' => 'requester',
                        'action' => 'submit',
                        'entry_status' => 'submitted',
                        'approve_status' => 'pending_director_personal',
                        'next_step_key' => 'director_personal_review',
                        'auto_complete' => true,
                    ],
                    [
                        'step_key' => 'accounting_review',
                        'name' => 'Review Accounting yang Diskip',
                        'actor_type' => 'role',
                        'actor_value' => 'Accounting',
                        'action' => 'approve',
                        'entry_status' => 'pending_accounting',
                        'approve_status' => 'completed',
                    ],
                    [
                        'step_key' => 'director_personal_review',
                        'name' => 'Review Direktur Personal',
                        'actor_type' => 'user',
                        'actor_value' => (string) $specificReviewer->id,
                        'action' => 'approve',
                        'entry_status' => 'pending_director_personal',
                        'approve_status' => 'completed',
                        'notes_required' => true,
                    ],
                    [
                        'step_key' => 'complete',
                        'name' => 'Selesai',
                        'actor_type' => 'system',
                        'action' => 'complete',
                        'entry_status' => 'completed',
                        'auto_complete' => true,
                    ],
                ],
            ]),
            'is_active' => true,
        ]);

        $form = Form::query()->create([
            'name' => 'Form Approval Lanjutan',
            'slug' => 'form-approval-lanjutan',
            'description' => 'Form untuk menguji workflow fleksibel.',
            'form_config' => [
                'fields' => [
                    [
                        'id' => 'reason',
                        'type' => 'textarea',
                        'label' => 'Alasan',
                        'required' => true,
                        'validation' => 'string',
                    ],
                ],
            ],
            'workflow_id' => $workflow->id,
            'is_active' => true,
        ]);

        $submitResponse = $this->actingAs($employee)->postJson('/api/form-submissions', [
            'form_id' => $form->id,
            'form_data' => [
                'reason' => 'Membutuhkan approval cepat dengan routing custom.',
            ],
        ]);

        $submitResponse
            ->assertCreated()
            ->assertJsonPath('submission.current_status', 'pending_director_personal')
            ->assertJsonPath('submission.current_step', 3)
            ->assertJsonPath('submission.current_pending_step.step_name', 'Review Direktur Personal')
            ->assertJsonPath('submission.current_pending_step.actor_type', 'user')
            ->assertJsonPath('submission.current_pending_step.actor_value', (string) $specificReviewer->id);

        $submissionId = $submitResponse->json('submission.id');

        $this->assertDatabaseMissing('approval_steps', [
            'form_submission_id' => $submissionId,
            'step_number' => 2,
        ]);

        $this->actingAs($accounting)
            ->putJson("/api/form-submissions/{$submissionId}/approve", [
                'notes' => 'Saya bukan approver spesifik.',
            ])
            ->assertStatus(403);

        $this->actingAs($specificReviewer)
            ->putJson("/api/form-submissions/{$submissionId}/approve", [])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Catatan wajib diisi untuk langkah ini.');

        $this->actingAs($specificReviewer)
            ->putJson("/api/form-submissions/{$submissionId}/approve", [
                'notes' => 'Disetujui oleh approver personal.',
            ])
            ->assertOk()
            ->assertJsonPath('submission.current_status', 'completed');
    }

    private function makeUserWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }
}
