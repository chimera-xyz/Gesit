<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Notification;
use App\Models\User;
use Database\Seeders\FormSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(WorkflowSeeder::class);
        $this->seed(FormSeeder::class);
    }

    public function test_submission_notifies_submitter_and_all_users_in_next_role(): void
    {
        $employee = $this->makeUserWithRole('Employee', [
            'department' => 'General Affairs',
            'employee_id' => 'EMP-401',
        ]);
        $itReviewerOne = $this->makeUserWithRole('IT Staff', [
            'department' => 'IT',
            'employee_id' => 'IT-401',
        ]);
        $itReviewerTwo = $this->makeUserWithRole('IT Staff', [
            'department' => 'IT',
            'employee_id' => 'IT-402',
        ]);
        $director = $this->makeUserWithRole('Operational Director', [
            'department' => 'Operational',
            'employee_id' => 'DIR-401',
        ]);

        $response = $this->actingAs($employee)->postJson('/api/form-submissions', [
            'form_id' => $this->procurementForm()->id,
            'form_data' => $this->validProcurementPayload(),
        ]);

        $response->assertCreated();

        $submissionId = $response->json('submission.id');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'form_submitted',
            'link' => "/submissions/{$submissionId}",
            'is_read' => false,
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $itReviewerOne->id,
            'type' => 'approval_needed',
            'link' => "/submissions/{$submissionId}",
            'is_read' => false,
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $itReviewerTwo->id,
            'type' => 'approval_needed',
            'link' => "/submissions/{$submissionId}",
            'is_read' => false,
        ]);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $director->id,
            'link' => "/submissions/{$submissionId}",
            'type' => 'approval_needed',
        ]);
    }

    public function test_notification_endpoints_keep_unread_count_consistent(): void
    {
        $user = $this->makeUserWithRole('Employee', [
            'department' => 'General Affairs',
            'employee_id' => 'EMP-402',
        ]);

        $unreadOne = Notification::create([
            'user_id' => $user->id,
            'title' => 'Approval baru',
            'message' => 'Ada approval yang menunggu tindakan Anda.',
            'type' => 'approval_needed',
            'link' => '/submissions/10',
            'is_read' => false,
        ]);

        Notification::create([
            'user_id' => $user->id,
            'title' => 'Status diperbarui',
            'message' => 'Status pengajuan berubah.',
            'type' => 'status_changed',
            'link' => '/submissions/11',
            'is_read' => false,
        ]);

        Notification::create([
            'user_id' => $user->id,
            'title' => 'Sudah dibaca',
            'message' => 'Notifikasi ini sudah dibaca.',
            'type' => 'general',
            'link' => '/submissions/12',
            'is_read' => true,
            'read_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 2);

        $this->actingAs($user)
            ->getJson('/api/notifications/unread-feed')
            ->assertOk()
            ->assertJsonPath('unread_count', 2)
            ->assertJsonCount(2, 'notifications');

        $this->actingAs($user)
            ->postJson("/api/notifications/{$unreadOne->id}/read")
            ->assertOk()
            ->assertJsonPath('unread_count', 1);

        $this->assertDatabaseHas('notifications', [
            'id' => $unreadOne->id,
            'is_read' => true,
        ]);

        $this->assertNotNull(Notification::query()->findOrFail($unreadOne->id)->read_at);

        $this->actingAs($user)
            ->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->actingAs($user)
            ->deleteJson("/api/notifications/{$unreadOne->id}")
            ->assertOk()
            ->assertJsonPath('unread_count', 0);
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
            'needed_by_date' => '2026-04-12',
            'estimated_cost' => 18500000,
            'vendor_preference' => 'Bhinneka',
        ];
    }
}
