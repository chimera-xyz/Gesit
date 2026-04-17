<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\HelpdeskTicket;
use App\Models\KnowledgeConversation;
use App\Models\KnowledgeConversationMessage;
use App\Support\S21PlusAccountService;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class S21PlusUnblockConversationTest extends TestCase
{
    use RefreshDatabase;

    private string $s21plusDatabasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config()->set('services.zai.api_key', null);

        $this->s21plusDatabasePath = tempnam(sys_get_temp_dir(), 'gesit-s21plus-');

        config()->set('database.connections.s21plus', [
            'driver' => 'sqlite',
            'database' => $this->s21plusDatabasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        DB::purge('s21plus');

        Schema::connection('s21plus')->create('User', function (Blueprint $table) {
            $table->string('UserID')->primary();
            $table->integer('IsEnabled');
            $table->integer('LoginRetry')->default(0);
        });

        $this->beforeApplicationDestroyed(function () {
            DB::purge('s21plus');

            if (is_file($this->s21plusDatabasePath)) {
                @unlink($this->s21plusDatabasePath);
            }
        });
    }

    public function test_blocked_s21plus_request_returns_confirmation_actions_and_logs_status_check(): void
    {
        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Lina Employee',
            'email' => 'lina.employee@example.com',
            's21plus_user_id' => 'lina',
        ]);

        $this->seedS21PlusAccount('lina', 0, 3);

        $response = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'akun S21Plus saya keblokir',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('assistant_message.actions.0.key', 's21plus_unlock_confirm')
            ->assertJsonPath('assistant_message.actions.0.label', 'Buka blokir sekarang')
            ->assertJsonPath('assistant_message.actions.1.key', 's21plus_contact_it');

        $this->assertStringContainsString(
            'terdeteksi dalam status terblokir',
            (string) $response->json('assistant_message.content')
        );

        $this->assertDatabaseHas('s21plus_unblock_audit_logs', [
            'gesit_user_id' => $user->id,
            's21plus_user_id' => 'lina',
            'request_type' => 'check_status',
            'result_code' => 'blocked_confirmed',
            'status' => 'completed',
            'before_is_enabled' => 0,
            'before_login_retry' => 3,
            'after_is_enabled' => 0,
            'after_login_retry' => 3,
        ]);
    }

    public function test_s21plus_login_issue_request_also_triggers_live_status_check(): void
    {
        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Lina Employee',
            'email' => 'lina.login-issue@example.com',
            's21plus_user_id' => 'lina',
        ]);

        $this->seedS21PlusAccount('lina', 0, 3);

        $response = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'akun s21plus saya gabisa login',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('assistant_message.actions.0.key', 's21plus_unlock_confirm');

        $this->assertStringContainsString(
            'terdeteksi dalam status terblokir',
            (string) $response->json('assistant_message.content')
        );
    }

    public function test_follow_up_blocked_message_uses_previous_s21plus_context(): void
    {
        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Lina Employee',
            'email' => 'lina.followup@example.com',
            's21plus_user_id' => 'lina',
        ]);

        $this->seedS21PlusAccount('lina', 0, 3);

        $firstResponse = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'akun s21plus saya gabisa login',
        ]);

        $followUpResponse = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'keblokir kayanya',
            'conversation_id' => $firstResponse->json('conversation.id'),
        ]);

        $followUpResponse
            ->assertOk()
            ->assertJsonPath('assistant_message.actions.0.key', 's21plus_unlock_confirm');

        $this->assertStringContainsString(
            'terdeteksi dalam status terblokir',
            (string) $followUpResponse->json('assistant_message.content')
        );
    }

    public function test_follow_up_check_again_keeps_using_live_s21plus_status_in_same_thread(): void
    {
        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Lina Employee',
            'email' => 'lina.recheck@example.com',
            's21plus_user_id' => 'lina',
        ]);

        $this->seedS21PlusAccount('lina', 0, 3);

        $firstResponse = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'akun s21plus gua keblokir',
        ]);

        $followUpResponse = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'coba cek lagi',
            'conversation_id' => $firstResponse->json('conversation.id'),
        ]);

        $followUpResponse
            ->assertOk()
            ->assertJsonPath('assistant_message.actions.0.key', 's21plus_unlock_confirm');

        $this->assertStringContainsString(
            'Saya cek langsung ke S21Plus sekarang',
            (string) $followUpResponse->json('assistant_message.content')
        );
        $this->assertStringContainsString(
            'IsEnabled = 0',
            (string) $followUpResponse->json('assistant_message.content')
        );
        $this->assertStringContainsString(
            'LoginRetry = 3',
            (string) $followUpResponse->json('assistant_message.content')
        );
    }

    public function test_follow_up_proof_request_returns_live_status_snapshot_in_same_thread(): void
    {
        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Lina Employee',
            'email' => 'lina.proof@example.com',
            's21plus_user_id' => 'lina',
        ]);

        $this->seedS21PlusAccount('lina', 1, 0);

        $firstResponse = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'akun s21plus saya gabisa login',
        ]);

        $proofResponse = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'coba mana buktinya',
            'conversation_id' => $firstResponse->json('conversation.id'),
        ]);

        $proofResponse->assertOk();

        $this->assertStringContainsString(
            'Saya cek langsung ke S21Plus sekarang',
            (string) $proofResponse->json('assistant_message.content')
        );
        $this->assertStringContainsString(
            'IsEnabled = 1',
            (string) $proofResponse->json('assistant_message.content')
        );
        $this->assertStringContainsString(
            'LoginRetry = 0',
            (string) $proofResponse->json('assistant_message.content')
        );
        $this->assertStringNotContainsString(
            'Saya tidak dapat melakukan pengecekan langsung',
            (string) $proofResponse->json('assistant_message.content')
        );
    }

    public function test_s21plus_cannot_open_phrase_also_triggers_live_status_check(): void
    {
        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Lina Employee',
            'email' => 'lina.cannot-open@example.com',
            's21plus_user_id' => 'lina',
        ]);

        $this->seedS21PlusAccount('lina', 0, 3);

        $response = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 's21plus gua gabisa kebuka',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('assistant_message.actions.0.key', 's21plus_unlock_confirm');

        $this->assertStringContainsString(
            'Saya cek langsung ke S21Plus sekarang',
            (string) $response->json('assistant_message.content')
        );
    }

    public function test_s21plus_plus_sign_and_cannot_be_opened_phrase_trigger_live_status_check(): void
    {
        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Lina Employee',
            'email' => 'lina.plus-sign@example.com',
            's21plus_user_id' => 'lina',
        ]);

        $this->seedS21PlusAccount('lina', 0, 3);

        $response = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'gua ada kendala nih s21+ gua gabisa dibuka',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('assistant_message.actions.0.key', 's21plus_unlock_confirm');

        $this->assertStringContainsString(
            'Saya cek langsung ke S21Plus sekarang',
            (string) $response->json('assistant_message.content')
        );
    }

    public function test_s21plus_application_phrase_with_plus_sign_avoids_generic_knowledge_answer(): void
    {
        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Lina Employee',
            'email' => 'lina.app-plus@example.com',
            's21plus_user_id' => 'lina',
        ]);

        $this->seedS21PlusAccount('lina', 0, 3);

        $response = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'aplikasi s21+ gua gabisa dibuka',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('assistant_message.actions.0.key', 's21plus_unlock_confirm');

        $this->assertStringContainsString(
            'terdeteksi dalam status terblokir',
            (string) $response->json('assistant_message.content')
        );
        $this->assertSame([], $response->json('assistant_message.sources'));
    }

    public function test_physical_samsung_s21_plus_issue_does_not_trigger_internal_s21plus_unblock_flow(): void
    {
        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Lina Employee',
            'email' => 'lina.phone-issue@example.com',
            's21plus_user_id' => 'lina',
        ]);

        $response = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'Samsung Galaxy S21+ saya tidak bisa dibuka',
        ]);

        $response->assertOk();

        $this->assertSame([], $response->json('assistant_message.actions'));
        $this->assertDatabaseCount('s21plus_unblock_audit_logs', 0);
    }

    public function test_unlock_action_updates_s21plus_status_and_logs_audit_trail(): void
    {
        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Lina Employee',
            'email' => 'lina.unlock@example.com',
            's21plus_user_id' => 'lina',
        ]);

        $this->seedS21PlusAccount('lina', 0, 3);

        $initialResponse = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'akun S21Plus saya keblokir',
        ]);

        $conversationId = $initialResponse->json('conversation.id');
        $assistantMessageId = $initialResponse->json('assistant_message.id');

        $actionResponse = $this->actingAs($user)->postJson("/api/knowledge-hub/conversations/{$conversationId}/actions", [
            'message_id' => $assistantMessageId,
            'action_key' => 's21plus_unlock_confirm',
        ]);

        $actionResponse
            ->assertOk()
            ->assertJsonPath('updated_message.id', $assistantMessageId)
            ->assertJsonPath('updated_message.actions', [])
            ->assertJsonPath('assistant_message.actions', []);

        $this->assertStringContainsString(
            'berhasil dibuka blokir',
            (string) $actionResponse->json('assistant_message.content')
        );

        $this->assertSame(
            1,
            (int) DB::connection('s21plus')->table('User')->where('UserID', 'lina')->value('IsEnabled')
        );
        $this->assertSame(
            0,
            (int) DB::connection('s21plus')->table('User')->where('UserID', 'lina')->value('LoginRetry')
        );

        $this->assertDatabaseHas('s21plus_unblock_audit_logs', [
            'gesit_user_id' => $user->id,
            's21plus_user_id' => 'lina',
            'request_type' => 'unlock',
            'result_code' => 'unlock_success',
            'status' => 'completed',
            'before_is_enabled' => 0,
            'before_login_retry' => 3,
            'after_is_enabled' => 1,
            'after_login_retry' => 0,
        ]);

        $this->assertDatabaseCount('knowledge_conversation_messages', 4);
    }

    public function test_contact_it_action_creates_helpdesk_ticket_with_s21plus_context_and_notifies_it(): void
    {
        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Lina Employee',
            'email' => 'lina.helpdesk@example.com',
            's21plus_user_id' => 'lina',
        ]);
        $itStaff = $this->makeUserWithRole('IT Staff', [
            'name' => 'Rizal IT',
            'email' => 'rizal.it@example.com',
        ]);

        $this->seedS21PlusAccount('lina', 0, 3);

        $initialResponse = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'akun s21plus saya gabisa login dan keblokir',
        ]);

        $initialResponse
            ->assertOk()
            ->assertJsonPath('assistant_message.actions.1.key', 's21plus_contact_it')
            ->assertJsonPath('assistant_message.actions.1.label', 'Buat ticket ke Tim IT');

        $actionResponse = $this->actingAs($user)->postJson('/api/knowledge-hub/conversations/'.$initialResponse->json('conversation.id').'/actions', [
            'message_id' => $initialResponse->json('assistant_message.id'),
            'action_key' => 's21plus_contact_it',
        ]);

        $actionResponse
            ->assertOk()
            ->assertJsonPath('updated_message.actions', [])
            ->assertJsonPath('assistant_message.actions', []);

        $this->assertStringContainsString(
            'ticket',
            Str::lower((string) $actionResponse->json('assistant_message.content'))
        );

        $ticket = HelpdeskTicket::query()->firstOrFail();

        $this->assertSame($user->id, (int) $ticket->requester_id);
        $this->assertSame('account_access', $ticket->category);
        $this->assertSame('portal', $ticket->channel);
        $this->assertSame('ai_s21plus', data_get($ticket->context, 'source'));
        $this->assertSame('s21plus_access', data_get($ticket->context, 'issue_type'));
        $this->assertSame($initialResponse->json('conversation.id'), data_get($ticket->context, 'conversation_id'));
        $this->assertSame('lina', data_get($ticket->context, 's21plus_user_id'));
        $this->assertSame('blocked_confirmed', data_get($ticket->context, 's21plus_result.result_code'));
        $this->assertFalse((bool) data_get($ticket->context, 's21plus_result.after.is_enabled'));
        $this->assertSame(3, data_get($ticket->context, 's21plus_result.after.login_retry'));
        $this->assertStringContainsString('S21Plus', $ticket->subject);
        $this->assertStringContainsString('gabisa login', Str::lower($ticket->description));
        $this->assertStringContainsString('IsEnabled = 0', $ticket->description);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $itStaff->id,
            'type' => 'general',
            'title' => 'Ticket bantuan baru masuk',
            'link' => "/helpdesk/{$ticket->id}",
        ]);
    }

    public function test_mapping_missing_response_offers_contact_it_action_and_creates_ticket(): void
    {
        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Rafi Employee',
            'email' => 'rafi.helpdesk@example.com',
            's21plus_user_id' => null,
        ]);
        $this->makeUserWithRole('IT Staff', [
            'name' => 'Dinda IT',
            'email' => 'dinda.it@example.com',
        ]);

        $initialResponse = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'akun s21plus saya keblokir',
        ]);

        $initialResponse
            ->assertOk()
            ->assertJsonPath('assistant_message.actions.0.key', 's21plus_contact_it');

        $actionResponse = $this->actingAs($user)->postJson('/api/knowledge-hub/conversations/'.$initialResponse->json('conversation.id').'/actions', [
            'message_id' => $initialResponse->json('assistant_message.id'),
            'action_key' => 's21plus_contact_it',
        ]);

        $actionResponse->assertOk();

        $ticket = HelpdeskTicket::query()->firstOrFail();

        $this->assertSame('mapping_missing', data_get($ticket->context, 's21plus_result.result_code'));
        $this->assertStringContainsString('mapping', Str::lower($ticket->subject.' '.$ticket->description));
    }

    public function test_contact_it_action_reuses_existing_active_s21plus_ticket(): void
    {
        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Lina Employee',
            'email' => 'lina.reuse@example.com',
            's21plus_user_id' => 'lina',
        ]);
        $this->makeUserWithRole('IT Staff', [
            'name' => 'Siska IT',
            'email' => 'siska.it@example.com',
        ]);

        $this->seedS21PlusAccount('lina', 0, 3);

        $firstResponse = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'akun s21plus saya keblokir',
        ]);

        $this->actingAs($user)->postJson('/api/knowledge-hub/conversations/'.$firstResponse->json('conversation.id').'/actions', [
            'message_id' => $firstResponse->json('assistant_message.id'),
            'action_key' => 's21plus_contact_it',
        ])->assertOk();

        $ticket = HelpdeskTicket::query()->firstOrFail();

        $secondResponse = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 's21plus saya masih gabisa login, tolong teruskan ke IT lagi',
        ]);

        $actionResponse = $this->actingAs($user)->postJson('/api/knowledge-hub/conversations/'.$secondResponse->json('conversation.id').'/actions', [
            'message_id' => $secondResponse->json('assistant_message.id'),
            'action_key' => 's21plus_contact_it',
        ]);

        $actionResponse->assertOk();

        $this->assertDatabaseCount('helpdesk_tickets', 1);
        $this->assertStringContainsString($ticket->ticket_number, (string) $actionResponse->json('assistant_message.content'));

        $ticket = $ticket->fresh(['updates']);

        $this->assertGreaterThanOrEqual(2, $ticket->updates->count());
        $this->assertStringContainsString(
            'eskalasi lanjutan',
            Str::lower((string) optional($ticket->updates->last())->message)
        );
    }

    public function test_unlock_failure_automatically_creates_helpdesk_ticket(): void
    {
        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Lina Employee',
            'email' => 'lina.unlock-fail@example.com',
            's21plus_user_id' => 'lina',
        ]);
        $this->makeUserWithRole('IT Staff', [
            'name' => 'Ardi IT',
            'email' => 'ardi.it@example.com',
        ]);

        $conversation = KnowledgeConversation::query()->create([
            'user_id' => $user->id,
            'title' => 'Akun S21Plus keblokir',
            'last_message_at' => now(),
        ]);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'akun s21plus saya keblokir dan gabisa login',
        ]);

        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Akun Anda terdeteksi terblokir. Mau saya buka blokir sekarang?',
            'provider' => 'system',
            'actions' => [
                [
                    'key' => 's21plus_unlock_confirm',
                    'label' => 'Buka blokir sekarang',
                    'variant' => 'primary',
                ],
            ],
        ]);

        $this->mock(S21PlusAccountService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('unlockOwnAccount')
                ->once()
                ->andReturn([
                    'audit_log_id' => 99,
                    'request_type' => 'unlock',
                    'status' => 'failed',
                    'result_code' => 'unlock_failed',
                    'message' => 'Proses unblock belum berhasil dijalankan di sistem S21Plus.',
                    's21plus_user_id' => 'lina',
                    'before' => [
                        'is_enabled' => false,
                        'login_retry' => 3,
                    ],
                    'after' => [
                        'is_enabled' => false,
                        'login_retry' => 3,
                    ],
                ]);
        });

        $response = $this->actingAs($user)->postJson("/api/knowledge-hub/conversations/{$conversation->id}/actions", [
            'message_id' => $assistantMessage->id,
            'action_key' => 's21plus_unlock_confirm',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('updated_message.actions', [])
            ->assertJsonPath('assistant_message.actions', []);

        $this->assertStringContainsString(
            'ticket',
            Str::lower((string) $response->json('assistant_message.content'))
        );

        $ticket = HelpdeskTicket::query()->firstOrFail();

        $this->assertSame('unlock_failed', data_get($ticket->context, 's21plus_result.result_code'));
        $this->assertSame($conversation->id, data_get($ticket->context, 'conversation_id'));
        $this->assertStringContainsString('unblock', Str::lower($ticket->subject.' '.$ticket->description));
    }

    public function test_missing_s21plus_mapping_returns_manual_it_guidance(): void
    {
        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Rafi Employee',
            'email' => 'rafi.employee@example.com',
            's21plus_user_id' => null,
        ]);

        $response = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'akun s21plus saya keblokir',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('assistant_message.actions.0.key', 's21plus_contact_it');

        $this->assertStringContainsString(
            'belum menemukan UserID S21Plus',
            (string) $response->json('assistant_message.content')
        );

        $this->assertDatabaseHas('s21plus_unblock_audit_logs', [
            'gesit_user_id' => $user->id,
            'request_type' => 'check_status',
            'result_code' => 'mapping_missing',
            'status' => 'failed',
        ]);
    }

    public function test_service_inspection_without_conversation_context_still_writes_audit_log(): void
    {
        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Lina Employee',
            'email' => 'lina.direct-service@example.com',
            's21plus_user_id' => 'lina',
        ]);

        $this->seedS21PlusAccount('lina', 1, 0);

        $result = app(S21PlusAccountService::class)->inspectOwnAccount($user);

        $this->assertSame('account_active', $result['result_code']);

        $this->assertDatabaseHas('s21plus_unblock_audit_logs', [
            'gesit_user_id' => $user->id,
            's21plus_user_id' => 'lina',
            'knowledge_conversation_id' => null,
            'knowledge_conversation_message_id' => null,
            'request_type' => 'check_status',
            'result_code' => 'account_active',
            'status' => 'completed',
        ]);
    }

    private function seedS21PlusAccount(string $userId, int $isEnabled, int $loginRetry): void
    {
        DB::connection('s21plus')->table('User')->insert([
            'UserID' => $userId,
            'IsEnabled' => $isEnabled,
            'LoginRetry' => $loginRetry,
        ]);
    }

    private function makeUserWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }
}
