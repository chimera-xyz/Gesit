<?php

namespace Tests\Feature;

use App\Models\KnowledgeConversation;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeSection;
use App\Models\KnowledgeSpace;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class KnowledgeConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config()->set('services.zai.api_key', null);
    }

    public function test_user_can_create_and_continue_a_knowledge_conversation(): void
    {
        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Dina Employee',
            'email' => 'dina.employee@example.com',
        ]);
        $entry = $this->seedInternalKnowledgeEntry();

        $firstResponse = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'Bagaimana SOP reimburse makan dinas?',
        ]);

        $firstResponse
            ->assertOk()
            ->assertJsonPath('conversation.title', 'Bagaimana SOP reimburse makan dinas?')
            ->assertJsonPath('conversation.message_count', 2)
            ->assertJsonPath('scope', 'internal')
            ->assertJsonPath('assistant_message.scopeLabel', 'Internal')
            ->assertJsonFragment([
                'title' => $entry->title,
            ]);

        $conversationId = $firstResponse->json('conversation.id');

        $this->assertDatabaseHas('knowledge_conversations', [
            'id' => $conversationId,
            'user_id' => $user->id,
            'title' => 'Bagaimana SOP reimburse makan dinas?',
        ]);
        $this->assertDatabaseCount('knowledge_conversation_messages', 2);

        $secondResponse = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'Siapa owner dokumennya?',
            'conversation_id' => $conversationId,
        ]);

        $secondResponse
            ->assertOk()
            ->assertJsonPath('conversation.id', $conversationId)
            ->assertJsonPath('conversation.message_count', 4)
            ->assertJsonPath('scope', 'internal')
            ->assertJsonPath('assistant_message.scopeLabel', 'Internal')
            ->assertJsonFragment([
                'title' => $entry->title,
            ]);

        $this->assertDatabaseCount('knowledge_conversations', 1);
        $this->assertDatabaseCount('knowledge_conversation_messages', 4);
    }

    public function test_user_can_list_search_and_view_only_own_conversations(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-15 09:30:00'));

        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Bagas Employee',
            'email' => 'bagas.employee@example.com',
        ]);
        $otherUser = $this->makeUserWithRole('Employee', [
            'name' => 'Rina Employee',
            'email' => 'rina.employee@example.com',
        ]);

        $conversation = KnowledgeConversation::query()->create([
            'user_id' => $user->id,
            'title' => 'Status reimburse finance',
            'last_message_at' => Carbon::now()->subMinutes(10),
        ]);
        $conversation->messages()->createMany([
            [
                'role' => 'user',
                'content' => 'Status reimburse finance bagaimana?',
                'created_at' => Carbon::now()->subMinutes(11),
                'updated_at' => Carbon::now()->subMinutes(11),
            ],
            [
                'role' => 'assistant',
                'content' => 'Owner proses reimburse ada di Finance Operation.',
                'scope' => 'internal',
                'sources' => [],
                'created_at' => Carbon::now()->subMinutes(10),
                'updated_at' => Carbon::now()->subMinutes(10),
            ],
        ]);

        $foreignConversation = KnowledgeConversation::query()->create([
            'user_id' => $otherUser->id,
            'title' => 'Obrolan user lain',
            'last_message_at' => Carbon::now()->subMinutes(5),
        ]);
        $foreignConversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Ini tidak boleh terlihat.',
            'scope' => 'internal',
        ]);

        $listResponse = $this->actingAs($user)->getJson('/api/knowledge-hub/conversations');

        $listResponse
            ->assertOk()
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.id', $conversation->id)
            ->assertJsonPath('conversations.0.title', 'Status reimburse finance');

        $searchResponse = $this->actingAs($user)->getJson('/api/knowledge-hub/conversations?search=owner');

        $searchResponse
            ->assertOk()
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.id', $conversation->id);

        $detailResponse = $this->actingAs($user)->getJson("/api/knowledge-hub/conversations/{$conversation->id}");

        $detailResponse
            ->assertOk()
            ->assertJsonPath('conversation.id', $conversation->id)
            ->assertJsonPath('conversation.message_count', 2)
            ->assertJsonPath('messages.1.scopeLabel', 'Internal');
    }

    public function test_user_can_rename_and_delete_own_conversation(): void
    {
        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Rafa Employee',
            'email' => 'rafa.employee@example.com',
        ]);

        $conversation = KnowledgeConversation::query()->create([
            'user_id' => $user->id,
            'title' => 'Judul awal obrolan',
            'last_message_at' => now(),
        ]);

        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Isi awal obrolan.',
            'scope' => 'internal',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/knowledge-hub/conversations/{$conversation->id}", [
                'title' => 'Panduan reimburse finance terbaru',
            ])
            ->assertOk()
            ->assertJsonPath('conversation.title', 'Panduan reimburse finance terbaru');

        $this->assertDatabaseHas('knowledge_conversations', [
            'id' => $conversation->id,
            'title' => 'Panduan reimburse finance terbaru',
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/knowledge-hub/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('deleted', true)
            ->assertJsonPath('conversation_id', $conversation->id);

        $this->assertDatabaseMissing('knowledge_conversations', [
            'id' => $conversation->id,
        ]);
        $this->assertDatabaseMissing('knowledge_conversation_messages', [
            'knowledge_conversation_id' => $conversation->id,
        ]);
    }

    public function test_user_cannot_view_another_users_conversation(): void
    {
        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Aldo Employee',
            'email' => 'aldo.employee@example.com',
        ]);
        $otherUser = $this->makeUserWithRole('Employee', [
            'name' => 'Citra Employee',
            'email' => 'citra.employee@example.com',
        ]);

        $conversation = KnowledgeConversation::query()->create([
            'user_id' => $otherUser->id,
            'title' => 'Percakapan pribadi',
            'last_message_at' => now(),
        ]);

        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Tidak boleh diakses user lain.',
            'scope' => 'internal',
        ]);

        $this->actingAs($user)
            ->getJson("/api/knowledge-hub/conversations/{$conversation->id}")
            ->assertNotFound();
    }

    private function seedInternalKnowledgeEntry(): KnowledgeEntry
    {
        $space = KnowledgeSpace::query()->create([
            'name' => 'Finance',
            'description' => 'Knowledge Finance',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $section = KnowledgeSection::query()->create([
            'knowledge_space_id' => $space->id,
            'name' => 'Reimburse & Approval',
            'description' => 'Panduan reimburse',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        return KnowledgeEntry::query()->create([
            'knowledge_section_id' => $section->id,
            'title' => 'SOP Reimburse Makan Dinas',
            'summary' => 'Panduan reimburse makan dinas untuk seluruh staff.',
            'body' => implode("\n", [
                '1. Staff mengisi formulir reimburse.',
                '2. Lampirkan bukti transaksi yang valid.',
                '3. Approval dilakukan oleh Finance Operation.',
                '4. Dana dibayarkan setelah verifikasi selesai.',
            ]),
            'scope' => 'internal',
            'type' => 'sop',
            'source_kind' => 'article',
            'owner_name' => 'Finance Operation',
            'reviewer_name' => 'Head of Finance',
            'version_label' => 'v2.1',
            'effective_date' => '2026-04-01',
            'reference_notes' => 'Halaman 5',
            'tags' => ['reimburse', 'finance'],
            'access_mode' => 'all',
            'sort_order' => 1,
            'is_active' => true,
        ]);
    }

    private function makeUserWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }
}
