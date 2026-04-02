<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\User;
use Database\Seeders\FormSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcurementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(WorkflowSeeder::class);
        $this->seed(FormSeeder::class);
    }

    public function test_employee_submission_initializes_it_review_step(): void
    {
        $employee = $this->makeUserWithRole('Employee', [
            'department' => 'General Affairs',
            'employee_id' => 'EMP-100',
        ]);

        $response = $this->actingAs($employee)->postJson('/api/form-submissions', [
            'form_id' => $this->procurementForm()->id,
            'form_data' => $this->validProcurementPayload(),
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('submission.current_status', 'pending_it')
            ->assertJsonPath('submission.current_step', 2)
            ->assertJsonPath('submission.current_pending_step.step_number', 2)
            ->assertJsonPath('submission.current_pending_step.approver_role', 'IT Staff');

        $submission = FormSubmission::firstOrFail();

        $this->assertDatabaseHas('approval_steps', [
            'form_submission_id' => $submission->id,
            'step_number' => 1,
            'status' => 'approved',
            'approver_id' => $employee->id,
        ]);

        $this->assertDatabaseHas('approval_steps', [
            'form_submission_id' => $submission->id,
            'step_number' => 2,
            'status' => 'pending',
            'approver_role' => 'IT Staff',
        ]);
    }

    public function test_procurement_submission_can_progress_until_completed(): void
    {
        Storage::fake('public');

        $employee = $this->makeUserWithRole('Employee', [
            'department' => 'General Affairs',
            'employee_id' => 'EMP-101',
        ]);
        $itReviewer = $this->makeUserWithRole('IT Staff', [
            'department' => 'IT',
            'employee_id' => 'IT-100',
        ]);
        $director = $this->makeUserWithRole('Operational Director', [
            'department' => 'Operational',
            'employee_id' => 'DIR-100',
        ]);
        $accounting = $this->makeUserWithRole('Accounting', [
            'department' => 'Finance',
            'employee_id' => 'ACC-100',
        ]);

        $submissionId = $this->actingAs($employee)->postJson('/api/form-submissions', [
            'form_id' => $this->procurementForm()->id,
            'form_data' => $this->validProcurementPayload(),
        ])->json('submission.id');

        $itStep = ApprovalStep::query()
            ->where('form_submission_id', $submissionId)
            ->where('step_number', 2)
            ->firstOrFail();
        $itSignatureId = $this->createSignatureForStep($itReviewer, $itStep->id);

        $this->actingAs($itReviewer)
            ->putJson("/api/form-submissions/{$submissionId}/approve", [
                'notes' => 'Layak diteruskan ke direktur.',
                'signature_id' => $itSignatureId,
            ])
            ->assertOk()
            ->assertJsonPath('submission.current_status', 'pending_director');

        $directorStep = ApprovalStep::query()
            ->where('form_submission_id', $submissionId)
            ->where('step_number', 3)
            ->firstOrFail();
        $directorSignatureId = $this->createSignatureForStep($director, $directorStep->id);

        $this->actingAs($director)
            ->putJson("/api/form-submissions/{$submissionId}/approve", [
                'notes' => 'Disetujui untuk proses accounting.',
                'signature_id' => $directorSignatureId,
            ])
            ->assertOk()
            ->assertJsonPath('submission.current_status', 'pending_accounting');

        $this->actingAs($accounting)
            ->putJson("/api/form-submissions/{$submissionId}/approve", [
                'notes' => 'Invoice diproses.',
            ])
            ->assertOk()
            ->assertJsonPath('submission.current_status', 'pending_payment');

        $accountingPaymentStep = ApprovalStep::query()
            ->where('form_submission_id', $submissionId)
            ->where('step_number', 5)
            ->firstOrFail();
        $accountingSignatureId = $this->createSignatureForStep($accounting, $accountingPaymentStep->id);

        $this->actingAs($accounting)
            ->putJson("/api/form-submissions/{$submissionId}/approve", [
                'notes' => 'Pembayaran selesai.',
                'signature_id' => $accountingSignatureId,
            ])
            ->assertOk()
            ->assertJsonPath('submission.current_status', 'completed');

        $this->assertDatabaseHas('approval_steps', [
            'form_submission_id' => $submissionId,
            'step_number' => 4,
            'status' => 'approved',
            'approver_id' => $accounting->id,
        ]);

        $this->assertDatabaseHas('approval_steps', [
            'form_submission_id' => $submissionId,
            'step_number' => 5,
            'status' => 'approved',
            'approver_id' => $accounting->id,
        ]);

        $this->assertDatabaseHas('approval_steps', [
            'form_submission_id' => $submissionId,
            'step_number' => 6,
            'status' => 'approved',
        ]);
    }

    public function test_it_can_revise_submission_and_must_sign_before_approval(): void
    {
        Storage::fake('public');

        $employee = $this->makeUserWithRole('Employee', [
            'department' => 'General Affairs',
            'employee_id' => 'EMP-102',
        ]);
        $itReviewer = $this->makeUserWithRole('IT Staff', [
            'department' => 'IT',
            'employee_id' => 'IT-102',
        ]);
        $director = $this->makeUserWithRole('Operational Director', [
            'department' => 'Operational',
            'employee_id' => 'DIR-102',
        ]);

        $submissionResponse = $this->actingAs($employee)->postJson('/api/form-submissions', [
            'form_id' => $this->procurementForm()->id,
            'form_data' => $this->validProcurementPayload(),
        ]);

        $submissionId = $submissionResponse->json('submission.id');
        $approvalStepId = $submissionResponse->json('submission.current_pending_step.id');

        $this->actingAs($itReviewer)
            ->putJson("/api/form-submissions/{$submissionId}/approve", [
                'notes' => 'Tidak boleh lolos tanpa tanda tangan.',
                'form_data' => [
                    'specifications' => 'Spesifikasi revisi dari IT.',
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Signature is required for this approval step');

        $signatureResponse = $this->actingAs($itReviewer)
            ->post('/api/signature/upload', [
                'approval_step_id' => $approvalStepId,
                'signature' => UploadedFile::fake()->image('it-signature.png'),
            ]);

        $signatureResponse->assertOk();
        $signatureId = $signatureResponse->json('signature.id');

        $this->actingAs($itReviewer)
            ->putJson("/api/form-submissions/{$submissionId}/approve", [
                'notes' => 'Spesifikasi sudah direvisi oleh IT.',
                'signature_id' => $signatureId,
                'form_data' => [
                    'specifications' => 'Spesifikasi revisi dari IT.',
                    'reason' => 'Reason yang sudah diperbarui tim IT.',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('submission.current_status', 'pending_director')
            ->assertJsonPath('submission.form_data.specifications', 'Spesifikasi revisi dari IT.');

        $this->assertDatabaseHas('form_submissions', [
            'id' => $submissionId,
            'current_status' => 'pending_director',
        ]);

        $this->assertDatabaseHas('approval_steps', [
            'form_submission_id' => $submissionId,
            'step_number' => 2,
            'status' => 'approved',
            'approver_id' => $itReviewer->id,
            'signature_id' => $signatureId,
        ]);

        $this->assertNotNull(FormSubmission::findOrFail($submissionId)->pdf_path);

        $this->actingAs($director)
            ->getJson("/api/pdf/preview/{$submissionId}")
            ->assertOk()
            ->assertJsonPath('success', true);
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

    private function createSignatureForStep(User $user, int $approvalStepId): int
    {
        $response = $this->actingAs($user)->post('/api/signature/upload', [
            'approval_step_id' => $approvalStepId,
            'signature' => UploadedFile::fake()->image("signature-{$approvalStepId}.png"),
        ]);

        $response->assertOk();

        return $response->json('signature.id');
    }
}
