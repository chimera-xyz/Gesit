<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\S21PlusAccountService;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            ->assertJsonPath('assistant_message.actions', []);

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
