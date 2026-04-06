<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\User;
use Database\Seeders\FormSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(WorkflowSeeder::class);
        $this->seed(FormSeeder::class);
    }

    public function test_admin_can_edit_form_without_changing_existing_submission_schema(): void
    {
        $employee = $this->makeUserWithRole('Employee', [
            'department' => 'General Affairs',
            'employee_id' => 'EMP-201',
        ]);
        $admin = $this->makeUserWithRole('Admin');
        $form = $this->procurementForm();

        $submissionResponse = $this->actingAs($employee)->postJson('/api/form-submissions', [
            'form_id' => $form->id,
            'form_data' => $this->validProcurementPayload(),
        ]);

        $submissionResponse->assertCreated();
        $submissionId = $submissionResponse->json('submission.id');

        $updatedFields = collect($form->form_config['fields'])
            ->reject(fn (array $field) => $field['id'] === 'reason')
            ->push([
                'id' => 'budget_code',
                'type' => 'text',
                'label' => 'Kode Budget',
                'required' => false,
                'validation' => 'nullable|string|max:50',
            ])
            ->values()
            ->all();

        $this->actingAs($admin)
            ->putJson("/api/forms/{$form->id}", [
                'name' => 'Form Pengadaan Hardware / Software Revisi',
                'description' => 'Versi revisi untuk request baru.',
                'workflow_id' => $form->workflow_id,
                'is_active' => true,
                'form_config' => [
                    'fields' => $updatedFields,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('form.name', 'Form Pengadaan Hardware / Software Revisi')
            ->assertJsonFragment(['id' => 'budget_code']);

        $submission = FormSubmission::query()->findOrFail($submissionId);

        $this->assertNotNull($submission->form_snapshot);
        $this->assertContains('reason', collect($submission->form_snapshot['fields'])->pluck('id')->all());
        $this->assertNotContains('budget_code', collect($submission->form_snapshot['fields'])->pluck('id')->all());

        $this->actingAs($admin)
            ->getJson("/api/form-submissions/{$submissionId}")
            ->assertOk()
            ->assertJsonFragment(['id' => 'reason'])
            ->assertJsonMissing(['id' => 'budget_code']);

        $this->actingAs($admin)
            ->getJson("/api/forms/{$form->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => 'budget_code']);
    }

    public function test_admin_can_toggle_form_status_and_delete_only_unused_forms(): void
    {
        $admin = $this->makeUserWithRole('Admin');
        $employee = $this->makeUserWithRole('Employee', [
            'department' => 'General Affairs',
            'employee_id' => 'EMP-202',
        ]);
        $usedForm = $this->procurementForm();
        $unusedForm = Form::query()->where('slug', 'password-reset-request')->firstOrFail();

        $this->actingAs($employee)->postJson('/api/form-submissions', [
            'form_id' => $usedForm->id,
            'form_data' => $this->validProcurementPayload(),
        ])->assertCreated();

        $this->actingAs($admin)
            ->putJson("/api/forms/{$usedForm->id}", [
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('form.is_active', false);

        $this->assertDatabaseHas('forms', [
            'id' => $usedForm->id,
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->putJson("/api/forms/{$usedForm->id}", [
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('form.is_active', true);

        $this->actingAs($admin)
            ->deleteJson("/api/forms/{$usedForm->id}")
            ->assertStatus(400);

        $this->assertDatabaseHas('forms', [
            'id' => $usedForm->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/forms/{$unusedForm->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('forms', [
            'id' => $unusedForm->id,
        ]);
    }

    private function procurementForm(): Form
    {
        return Form::query()->where('slug', 'hardware-software-procurement')->firstOrFail();
    }

    private function makeUserWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function validProcurementPayload(): array
    {
        return [
            'item_name' => 'Laptop Kerja',
            'item_type' => 'Hardware',
            'quantity' => 1,
            'specifications' => 'RAM 16GB, SSD 512GB, prosesor i7.',
            'reason' => 'Mendukung pekerjaan operasional harian dan meeting klien.',
            'urgency' => 'Urgent',
            'needed_by_date' => '2026-04-10',
            'estimated_cost' => 18500000,
            'vendor_preference' => 'Bhinneka',
        ];
    }
}
