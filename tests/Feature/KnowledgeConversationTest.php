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

    public function test_basic_chat_does_not_require_knowledge_evidence(): void
    {
        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Nadia Employee',
            'email' => 'nadia.employee@example.com',
        ]);

        $this->seedInternalKnowledgeEntry([
            'title' => 'Test Gambar',
            'summary' => 'Dokumen dummy untuk upload gambar.',
            'body' => 'Konten ini tidak boleh muncul hanya karena user mengetik test.',
            'type' => 'form',
        ]);

        $identityResponse = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'halo? lu siapa?',
        ]);

        $identityResponse
            ->assertOk()
            ->assertJsonPath('scope', 'conversation')
            ->assertJsonPath('assistant_message.scopeLabel', null)
            ->assertJsonPath('sources', []);

        $testResponse = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'test',
            'conversation_id' => $identityResponse->json('conversation.id'),
        ]);

        $testResponse
            ->assertOk()
            ->assertJsonPath('scope', 'conversation')
            ->assertJsonPath('sources', [])
            ->assertJsonMissing([
                'title' => 'Test Gambar',
            ]);
    }

    public function test_knowledge_question_returns_relevant_document_with_suggested_page(): void
    {
        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Hana Employee',
            'email' => 'hana.employee@example.com',
        ]);

        $entry = $this->seedInternalKnowledgeEntry([
            'title' => 'Checklist Housekeeping Setelah MKBD',
            'summary' => 'Panduan housekeeping operasional setelah proses MKBD selesai.',
            'body' => "[Halaman 1]\nPembukaan dokumen operasional.\n\n[Halaman 12]\nPastikan housekeeping dilakukan setelah MKBD dari Accounting selesai, lalu ikuti checklist operasional.",
            'reference_notes' => null,
            'tags' => ['housekeeping', 'mkbd'],
        ]);

        $response = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'gua lupa cara housekeeping nih',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('scope', 'internal')
            ->assertJsonPath('sources.0.id', $entry->id)
            ->assertJsonPath('sources.0.suggested_page', 12)
            ->assertJsonPath('assistant_message.sources.0.suggested_page', 12);
    }

    public function test_new_knowledge_topic_does_not_reuse_previous_document_sources(): void
    {
        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Irwan Employee',
            'email' => 'irwan.employee@example.com',
        ]);

        $housekeepingEntry = $this->seedInternalKnowledgeEntry([
            'title' => 'Housekeeping',
            'summary' => 'Housekeeping dilakukan setelah MKBD dari Accounting.',
            'body' => 'Housekeeping dilakukan setelah MKBD dari Accounting selesai.',
            'reference_notes' => 'Halaman 1',
            'tags' => ['housekeeping', 'mkbd'],
        ]);

        $firstResponse = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'saya lupa cara housekeeping boleh dibantu?',
        ]);

        $firstResponse
            ->assertOk()
            ->assertJsonPath('sources.0.id', $housekeepingEntry->id);

        $secondResponse = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'gimana cara import logbook csv di outlook?',
            'conversation_id' => $firstResponse->json('conversation.id'),
        ]);

        $secondResponse
            ->assertOk()
            ->assertJsonPath('sources', [])
            ->assertJsonMissing([
                'title' => 'Housekeeping',
            ]);
    }

    public function test_ambiguous_it_sore_sop_query_filters_unrelated_documents(): void
    {
        $user = $this->makeUserWithRole('Employee', [
            'name' => 'Tama Employee',
            'email' => 'tama.employee@example.com',
        ]);

        $itSpace = KnowledgeSpace::query()->create([
            'name' => 'IT',
            'kind' => 'division',
            'description' => 'Knowledge IT',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $itSection = KnowledgeSection::query()->create([
            'knowledge_space_id' => $itSpace->id,
            'name' => 'SOP',
            'description' => 'SOP IT',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $housekeeping = KnowledgeEntry::query()->create([
            'knowledge_section_id' => $itSection->id,
            'title' => 'HouseKeeping',
            'summary' => 'SOP operasional rutin harian sore untuk divisi IT.',
            'body' => '[Halaman 1] PROSEDUR OPERASI STANDAR Divisi Teknologi Informasi Judul: Operasional Rutin Harian (Sore) No: SOP/IT/20. Housekeeping dilakukan setelah MKBD dari Accounting.',
            'scope' => 'internal',
            'type' => 'sop',
            'source_kind' => 'file',
            'owner_name' => 'IT Operation',
            'version_label' => 'Belum diisi',
            'reference_notes' => 'Halaman 1',
            'tags' => ['housekeeping', 'sore'],
            'access_mode' => 'all',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        KnowledgeEntry::query()->create([
            'knowledge_section_id' => $itSection->id,
            'title' => 'Onboarding IT 7 Hari Pertama',
            'summary' => 'Daftar materi wajib untuk anggota baru tim IT.',
            'body' => 'Materi pengenalan tim, akses awal, dan daftar aplikasi internal untuk anggota baru IT.',
            'scope' => 'internal',
            'type' => 'troubleshooting',
            'source_kind' => 'article',
            'access_mode' => 'all',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        $salesSpace = KnowledgeSpace::query()->create([
            'name' => 'Sales',
            'kind' => 'division',
            'description' => 'Knowledge Sales',
            'sort_order' => 2,
            'is_active' => true,
        ]);
        $salesSection = KnowledgeSection::query()->create([
            'knowledge_space_id' => $salesSpace->id,
            'name' => 'test',
            'description' => 'Dummy upload',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        KnowledgeEntry::query()->create([
            'knowledge_section_id' => $salesSection->id,
            'title' => 'Test File',
            'summary' => 'Dokumen dummy asset profile.',
            'body' => 'KODE ASSET MON-0003-2026 Generated I N V E N TA R I S I T Asset Profile Document.',
            'scope' => 'internal',
            'type' => 'troubleshooting',
            'source_kind' => 'file',
            'access_mode' => 'all',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $financeSpace = KnowledgeSpace::query()->create([
            'name' => 'Finance',
            'kind' => 'division',
            'description' => 'Knowledge Finance',
            'sort_order' => 3,
            'is_active' => true,
        ]);
        $financeSection = KnowledgeSection::query()->create([
            'knowledge_space_id' => $financeSpace->id,
            'name' => 'Reimburse & Approval',
            'description' => 'Panduan reimburse',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        KnowledgeEntry::query()->create([
            'knowledge_section_id' => $financeSection->id,
            'title' => 'SOP Reimburse Makan Dinas',
            'summary' => 'Alur pengajuan reimburse makan dinas dari submit form sampai pembayaran.',
            'body' => 'Staff mengisi formulir reimburse dan approval dilakukan oleh Finance Operation.',
            'scope' => 'internal',
            'type' => 'sop',
            'source_kind' => 'article',
            'access_mode' => 'all',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'saya lupa cara sop sore gitu apa namanya ya karna sayakan divisi It tuh',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('sources.0.id', $housekeeping->id)
            ->assertJsonMissing(['title' => 'Onboarding IT 7 Hari Pertama'])
            ->assertJsonMissing(['title' => 'Test File'])
            ->assertJsonMissing(['title' => 'SOP Reimburse Makan Dinas']);
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

    private function seedInternalKnowledgeEntry(array $overrides = []): KnowledgeEntry
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

        return KnowledgeEntry::query()->create(array_merge([
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
        ], $overrides));
    }

    private function makeUserWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }
}
