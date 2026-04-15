<?php

namespace Tests\Feature;

use App\Models\ApprovalStep;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\HelpdeskTicket;
use App\Models\HelpdeskTicketUpdate;
use App\Models\User;
use App\Models\Workflow;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use ZipArchive;

class ItActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_it_staff_can_view_aggregated_it_activities(): void
    {
        [$itStaff] = $this->seedActivityFixtures();

        $response = $this->actingAs($itStaff)->getJson('/api/it-activities');

        $response
            ->assertOk()
            ->assertJsonPath('stats.total', 4)
            ->assertJsonPath('stats.helpdesk', 2)
            ->assertJsonPath('stats.submission', 2)
            ->assertJsonFragment([
                'activity_name' => 'Pengajuan dibuat',
                'reference_number' => 'SUB-0001',
            ])
            ->assertJsonFragment([
                'activity_name' => 'Masuk ke tahap Review Kelayakan IT',
            ])
            ->assertJsonFragment([
                'activity_name' => 'Ticket bantuan dibuat',
                'reference_number' => 'HD-20260407-0001',
            ])
            ->assertJsonFragment([
                'activity_name' => 'Update ticket ditambahkan',
                'actor_name' => 'Rizky IT',
            ]);
    }

    public function test_export_returns_excel_file_for_it_staff(): void
    {
        [$itStaff] = $this->seedActivityFixtures();

        $response = $this->actingAs($itStaff)->get('/api/it-activities/export');

        $response->assertOk();
        $this->assertStringContainsString('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment; filename="it-activities-', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('.xlsx"', (string) $response->headers->get('content-disposition'));

        $temporaryPath = tempnam(sys_get_temp_dir(), 'it-activities-test-');
        $this->assertNotFalse($temporaryPath);
        file_put_contents($temporaryPath, $response->getContent());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($temporaryPath));
        $this->assertNotFalse($zip->locateName('xl/media/logo.png'));
        $this->assertNotFalse($zip->locateName('xl/drawings/drawing1.xml'));
        $this->assertNotFalse($zip->locateName('xl/worksheets/sheet1.xml'));

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertIsString($sheetXml);
        $this->assertStringContainsString('Laporan Aktivitas IT', $sheetXml);
        $this->assertStringContainsString('Review Kelayakan IT', $sheetXml);
        $this->assertStringContainsString('Ticket bantuan dibuat', $sheetXml);

        $zip->close();
        @unlink($temporaryPath);
    }

    public function test_employee_cannot_access_it_activity_endpoints(): void
    {
        $employee = $this->makeUserWithRole('Employee', [
            'name' => 'Bagus Employee',
            'email' => 'bagus.employee@example.com',
        ]);

        $this->actingAs($employee)
            ->getJson('/api/it-activities')
            ->assertForbidden();

        $this->actingAs($employee)
            ->get('/api/it-activities/export')
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function seedActivityFixtures(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-04-07 13:30:00'));

        $employee = $this->makeUserWithRole('Employee', [
            'name' => 'Ayu User',
            'email' => 'ayu.user@example.com',
            'department' => 'General Affairs',
        ]);
        $itStaff = $this->makeUserWithRole('IT Staff', [
            'name' => 'Rizky IT',
            'email' => 'rizky.it@example.com',
            'department' => 'IT',
        ]);

        $workflow = Workflow::query()->create([
            'name' => 'Workflow Laptop',
            'slug' => 'workflow-laptop',
            'description' => 'Workflow pengajuan laptop baru',
            'workflow_config' => [
                'steps' => [
                    [
                        'step_number' => 1,
                        'step_key' => 'submit_request',
                        'name' => 'Pengajuan Dibuat',
                        'actor_type' => 'requester',
                        'action' => 'submit',
                        'entry_status' => 'submitted',
                        'approve_status' => 'pending_it',
                    ],
                    [
                        'step_number' => 2,
                        'step_key' => 'it_review',
                        'name' => 'Review Kelayakan IT',
                        'actor_type' => 'role',
                        'actor_value' => 'IT Staff',
                        'actor_label' => 'IT Staff',
                        'entry_status' => 'pending_it',
                        'approve_status' => 'pending_director',
                        'reject_status' => 'rejected',
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        $form = Form::query()->create([
            'name' => 'Pengadaan Laptop',
            'slug' => 'pengadaan-laptop',
            'description' => 'Pengajuan laptop operasional',
            'form_config' => [],
            'workflow_id' => $workflow->id,
            'is_active' => true,
        ]);

        $submission = FormSubmission::query()->create([
            'form_id' => $form->id,
            'user_id' => $employee->id,
            'form_data' => [],
            'form_snapshot' => [],
            'workflow_snapshot' => $workflow->workflow_config,
            'current_status' => 'pending_it',
            'current_step' => 2,
            'created_by' => $employee->id,
        ]);
        $submission->forceFill([
            'created_at' => Carbon::now()->subHours(4),
            'updated_at' => Carbon::now()->subHours(4),
        ])->save();

        $approvalStep = ApprovalStep::query()->create([
            'form_submission_id' => $submission->id,
            'step_number' => 2,
            'step_key' => 'it_review',
            'step_name' => 'Review Kelayakan IT',
            'approver_role' => 'IT Staff',
            'actor_type' => 'role',
            'actor_value' => 'IT Staff',
            'actor_label' => 'IT Staff',
            'status' => 'pending',
            'config_snapshot' => [
                'entry_status' => 'pending_it',
                'approve_status' => 'pending_director',
                'reject_status' => 'rejected',
            ],
        ]);
        $approvalStep->forceFill([
            'created_at' => Carbon::now()->subHours(3),
            'updated_at' => Carbon::now()->subHours(3),
        ])->save();

        $ticket = HelpdeskTicket::query()->create([
            'ticket_number' => 'HD-20260407-0001',
            'requester_id' => $employee->id,
            'created_by' => $employee->id,
            'assigned_to' => $itStaff->id,
            'subject' => 'Laptop office tidak bisa login',
            'description' => 'User tidak bisa login ke laptop office sejak pagi.',
            'category' => 'account_access',
            'channel' => 'portal',
            'priority' => 'high',
            'status' => 'in_progress',
            'assigned_at' => Carbon::now()->subHours(2),
            'last_activity_at' => Carbon::now()->subHour(),
        ]);
        $ticket->forceFill([
            'created_at' => Carbon::now()->subHours(2),
            'updated_at' => Carbon::now()->subHour(),
        ])->save();

        $createdUpdate = HelpdeskTicketUpdate::query()->create([
            'helpdesk_ticket_id' => $ticket->id,
            'user_id' => $employee->id,
            'type' => 'created',
            'message' => 'Ticket bantuan dibuat dari portal helpdesk.',
            'is_internal' => false,
            'meta' => [
                'channel' => 'portal',
            ],
        ]);
        $createdUpdate->forceFill([
            'created_at' => Carbon::now()->subHours(2),
            'updated_at' => Carbon::now()->subHours(2),
        ])->save();

        $commentUpdate = HelpdeskTicketUpdate::query()->create([
            'helpdesk_ticket_id' => $ticket->id,
            'user_id' => $itStaff->id,
            'type' => 'comment',
            'message' => 'Perangkat sedang dicek oleh tim IT.',
            'is_internal' => false,
        ]);
        $commentUpdate->forceFill([
            'created_at' => Carbon::now()->subHour(),
            'updated_at' => Carbon::now()->subHour(),
        ])->save();

        return [$itStaff, $employee];
    }

    private function makeUserWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }
}
