<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_direct_message_is_realtime_between_two_users_and_updates_read_delivery(): void
    {
        $sender = $this->makeUserWithRole('Employee', [
            'name' => 'Raihan Ops',
            'email' => 'raihan.ops@example.com',
            'department' => 'Operations',
        ]);
        $recipient = $this->makeUserWithRole('IT Staff', [
            'name' => 'Budi IT',
            'email' => 'budi.it@example.com',
            'department' => 'IT',
        ]);

        $conversationId = $this->ensureDirectConversation($sender, $recipient);

        $this->actingAs($sender)
            ->postJson("/api/chat/conversations/{$conversationId}/messages", [
                'text' => 'Halo Budi, tolong cek koneksi VPN ya.',
                'client_token' => 'msg-001',
            ])
            ->assertCreated();

        $recipientSync = $this->actingAs($recipient)
            ->getJson('/api/chat/sync?after_event_id=0&wait_seconds=0')
            ->assertOk();

        $recipientConversation = $this->conversationFromWorkspace(
            $recipientSync->json('workspace'),
            'Raihan Ops',
        );

        $this->assertSame('Halo Budi, tolong cek koneksi VPN ya.', $recipientConversation['preview']);
        $this->assertSame(1, $recipientConversation['unread_count']);
        $this->assertSame('Raihan Ops', $recipientSync->json("workspace.messages_by_conversation.{$conversationId}.0.sender_name"));

        $this->actingAs($recipient)
            ->postJson("/api/chat/conversations/{$conversationId}/read")
            ->assertOk();

        $senderWorkspace = $this->actingAs($sender)
            ->getJson('/api/chat/workspace')
            ->assertOk();

        $this->assertSame(
            'read',
            $senderWorkspace->json("workspace.messages_by_conversation.{$conversationId}.0.delivery"),
        );
    }

    public function test_direct_call_lifecycle_syncs_between_two_users(): void
    {
        $caller = $this->makeUserWithRole('Employee', [
            'name' => 'Raihan Ops',
            'email' => 'raihan.call@example.com',
        ]);
        $callee = $this->makeUserWithRole('Accounting', [
            'name' => 'Dina Accounting',
            'email' => 'dina.accounting@example.com',
        ]);

        $conversationId = $this->ensureDirectConversation($caller, $callee);

        $callStartResponse = $this->actingAs($caller)
            ->postJson("/api/chat/conversations/{$conversationId}/calls", [
                'type' => 'voice',
            ])
            ->assertCreated();

        $callId = $callStartResponse->json('workspace.active_call.id');
        $this->assertSame('ringing', $callStartResponse->json('workspace.active_call.status'));
        $this->assertFalse($callStartResponse->json('workspace.active_call.is_incoming'));

        $calleeSync = $this->actingAs($callee)
            ->getJson('/api/chat/sync?after_event_id=0&wait_seconds=0')
            ->assertOk();

        $this->assertSame('ringing', $calleeSync->json('workspace.active_call.status'));
        $this->assertTrue($calleeSync->json('workspace.active_call.is_incoming'));

        $this->actingAs($callee)
            ->postJson("/api/chat/calls/{$callId}/accept")
            ->assertOk()
            ->assertJsonPath('workspace.active_call.status', 'active');

        $callerWorkspace = $this->actingAs($caller)
            ->getJson('/api/chat/workspace')
            ->assertOk();

        $this->assertSame('active', $callerWorkspace->json('workspace.active_call.status'));

        $this->actingAs($caller)
            ->postJson("/api/chat/calls/{$callId}/end")
            ->assertOk()
            ->assertJsonPath('workspace.active_call', null);

        $calleeWorkspace = $this->actingAs($callee)
            ->getJson('/api/chat/workspace')
            ->assertOk();

        $lastMessage = collect($calleeWorkspace->json("workspace.messages_by_conversation.{$conversationId}"))->last();
        $this->assertNotNull($lastMessage);
        $this->assertTrue($lastMessage['is_system']);
        $this->assertStringContainsString('Panggilan suara selesai', $lastMessage['text']);
    }

    public function test_attachment_message_appears_in_recipient_assets(): void
    {
        Storage::fake('public');

        $sender = $this->makeUserWithRole('Employee', [
            'name' => 'Raihan Ops',
            'email' => 'raihan.file@example.com',
        ]);
        $recipient = $this->makeUserWithRole('IT Staff', [
            'name' => 'Nadia Support',
            'email' => 'nadia.support@example.com',
        ]);

        $conversationId = $this->ensureDirectConversation($sender, $recipient);

        $this->actingAs($sender)
            ->postJson("/api/chat/conversations/{$conversationId}/attachments", [
                'attachment' => UploadedFile::fake()->create(
                    'laporan-final.pdf',
                    256,
                    'application/pdf',
                ),
                'caption' => 'Draft laporan final.',
                'client_token' => 'file-001',
            ])
            ->assertCreated();

        $recipientWorkspace = $this->actingAs($recipient)
            ->getJson('/api/chat/workspace')
            ->assertOk();

        $message = collect($recipientWorkspace->json("workspace.messages_by_conversation.{$conversationId}"))->last();
        $asset = collect($recipientWorkspace->json("workspace.assets_by_conversation.{$conversationId}"))->first();

        $this->assertTrue($message['has_attachment']);
        $this->assertSame('laporan-final.pdf', $message['attachment_label']);
        $this->assertSame('laporan-final.pdf', $asset['label']);
        $this->assertSame('PDF', $asset['type_label']);
        Storage::disk('public')->assertExists('chat-attachments/'.$this->storedAttachmentName());
    }

    private function ensureDirectConversation(User $actor, User $participant): string
    {
        $response = $this->actingAs($actor)
            ->postJson('/api/chat/direct-conversations', [
                'participant_user_id' => $participant->id,
            ])
            ->assertCreated();

        $conversation = $this->conversationFromWorkspace(
            $response->json('workspace'),
            $participant->name,
        );

        return (string) $conversation['id'];
    }

    private function conversationFromWorkspace(array $workspace, string $title): array
    {
        $conversation = collect($workspace['conversations'] ?? [])
            ->firstWhere('title', $title);

        $this->assertNotNull($conversation, 'Conversation not found in workspace for title '.$title);

        return $conversation;
    }

    private function makeUserWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function storedAttachmentName(): string
    {
        $files = Collection::make(Storage::disk('public')->allFiles('chat-attachments'));
        $this->assertCount(1, $files);

        return basename($files->first());
    }
}
