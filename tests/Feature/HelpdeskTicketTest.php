<?php

namespace Tests\Feature;

use App\Models\HelpdeskTicket;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HelpdeskTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_employee_can_create_helpdesk_ticket_and_it_receives_notification(): void
    {
        $employee = $this->makeUserWithRole('Employee', [
            'name' => 'Nadia User',
            'email' => 'nadia@example.com',
        ]);
        $itStaff = $this->makeUserWithRole('IT Staff', [
            'name' => 'Rizal IT',
            'email' => 'rizal.it@example.com',
        ]);

        $response = $this->actingAs($employee)->postJson('/api/helpdesk/tickets', [
            'category' => 'hardware',
            'subject' => 'Mouse tidak terdeteksi',
            'description' => 'Mouse saya tidak berfungsi sejak pagi dan tidak terdeteksi di laptop.',
            'is_blocking' => true,
            'context' => [
                'page' => '/dashboard',
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('ticket.requester.id', $employee->id)
            ->assertJsonPath('ticket.category', 'hardware')
            ->assertJsonPath('ticket.status', 'open');

        $ticketId = $response->json('ticket.id');

        $this->assertDatabaseHas('helpdesk_tickets', [
            'id' => $ticketId,
            'requester_id' => $employee->id,
            'subject' => 'Mouse tidak terdeteksi',
            'status' => 'open',
            'channel' => 'portal',
            'priority' => 'high',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $itStaff->id,
            'type' => 'general',
            'title' => 'Ticket bantuan baru masuk',
            'link' => "/helpdesk/{$ticketId}",
        ]);
    }

    public function test_it_staff_can_log_phone_ticket_and_assign_to_self(): void
    {
        $itStaff = $this->makeUserWithRole('IT Staff', [
            'name' => 'Siska IT',
            'email' => 'siska.it@example.com',
        ]);
        $employee = $this->makeUserWithRole('Employee', [
            'name' => 'Dimas User',
            'email' => 'dimas@example.com',
        ]);

        $response = $this->actingAs($itStaff)->postJson('/api/helpdesk/tickets', [
            'requester_id' => $employee->id,
            'channel' => 'phone',
            'category' => 'printer',
            'subject' => 'Printer lantai 3 macet',
            'description' => 'User menelepon karena printer tidak menarik kertas.',
            'assign_to_me' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('ticket.requester.id', $employee->id)
            ->assertJsonPath('ticket.assignee.id', $itStaff->id)
            ->assertJsonPath('ticket.channel', 'phone')
            ->assertJsonPath('ticket.status', 'in_progress');

        $ticketId = $response->json('ticket.id');

        $this->assertDatabaseHas('helpdesk_tickets', [
            'id' => $ticketId,
            'requester_id' => $employee->id,
            'created_by' => $itStaff->id,
            'assigned_to' => $itStaff->id,
            'channel' => 'phone',
            'status' => 'in_progress',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'general',
            'title' => 'Ticket bantuan berhasil dicatat',
            'link' => "/helpdesk/{$ticketId}",
        ]);
    }

    public function test_requester_only_sees_own_tickets_and_can_reopen_resolved_ticket(): void
    {
        $requester = $this->makeUserWithRole('Employee', [
            'name' => 'Mila User',
            'email' => 'mila@example.com',
        ]);
        $otherRequester = $this->makeUserWithRole('Employee', [
            'email' => 'other@example.com',
        ]);
        $itStaff = $this->makeUserWithRole('IT Staff', [
            'name' => 'Ardi IT',
            'email' => 'ardi.it@example.com',
        ]);

        $ownTicket = HelpdeskTicket::query()->create([
            'ticket_number' => 'HD-20260402-0001',
            'requester_id' => $requester->id,
            'created_by' => $requester->id,
            'assigned_to' => $itStaff->id,
            'subject' => 'VPN belum tersambung',
            'description' => 'VPN masih gagal dipakai dari pagi.',
            'category' => 'internet',
            'channel' => 'portal',
            'priority' => 'normal',
            'status' => 'resolved',
            'resolved_at' => now(),
            'last_activity_at' => now(),
        ]);

        HelpdeskTicket::query()->create([
            'ticket_number' => 'HD-20260402-0002',
            'requester_id' => $otherRequester->id,
            'created_by' => $otherRequester->id,
            'subject' => 'Outlook error',
            'description' => 'Aplikasi email error.',
            'category' => 'email',
            'channel' => 'portal',
            'priority' => 'normal',
            'status' => 'open',
            'last_activity_at' => now(),
        ]);

        $this->actingAs($requester)
            ->getJson('/api/helpdesk/tickets')
            ->assertOk()
            ->assertJsonCount(1, 'tickets')
            ->assertJsonPath('tickets.0.id', $ownTicket->id);

        $this->actingAs($requester)
            ->putJson("/api/helpdesk/tickets/{$ownTicket->id}", [
                'action' => 'reopen',
            ])
            ->assertOk()
            ->assertJsonPath('ticket.status', 'open');

        $this->assertDatabaseHas('helpdesk_tickets', [
            'id' => $ownTicket->id,
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $itStaff->id,
            'type' => 'general',
            'title' => 'Ticket bantuan dibuka kembali',
            'link' => "/helpdesk/{$ownTicket->id}",
        ]);
    }

    private function makeUserWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }
}
