<?php

namespace Tests\Feature;

use App\Models\KnowledgeEntry;
use App\Models\KnowledgeSection;
use App\Models\KnowledgeSpace;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_fetch_general_knowledge_and_division_collection(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->getJson('/api/knowledge-admin')
            ->assertOk()
            ->assertJsonPath('general.kind', 'general')
            ->assertJsonPath('general.ai_provider', 'zai')
            ->assertJsonPath('general.show_in_hub', false)
            ->assertJsonCount(0, 'divisions');
    }

    public function test_admin_can_update_general_settings_and_store_document_directly_under_division(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->putJson('/api/knowledge-admin/general', [
                'description' => 'Knowledge utama untuk seluruh AI assistant.',
                'ai_instruction' => 'Jangan mengarang dan prioritaskan SOP terbaru.',
                'knowledge_text' => 'Gunakan bahasa Indonesia dan arahkan user ke dokumen jika perlu detail.',
                'ai_provider' => 'local',
                'ai_local_base_url' => '192.168.1.55:8080',
                'ai_local_api_key' => 'secret-local-key',
                'ai_local_model' => 'model-ai-yulie.gguf',
                'ai_local_timeout' => 60,
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('general.ai_instruction', 'Jangan mengarang dan prioritaskan SOP terbaru.')
            ->assertJsonPath('general.ai_provider', 'local')
            ->assertJsonPath('general.ai_local_base_url', '192.168.1.55:8080')
            ->assertJsonPath('general.ai_local_model', 'model-ai-yulie.gguf')
            ->assertJsonPath('general.ai_local_timeout', 60)
            ->assertJsonPath('general.has_ai_local_api_key', true)
            ->assertJsonMissingPath('general.ai_local_api_key');

        $divisionResponse = $this->actingAs($admin)
            ->postJson('/api/knowledge-admin/spaces', [
                'name' => 'IT',
                'description' => 'Knowledge divisi IT',
                'ai_instruction' => 'Jawab step-by-step untuk troubleshooting.',
                'knowledge_text' => 'IT menangani password, email, printer, jaringan, dan laptop.',
                'sort_order' => 1,
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('division.name', 'IT');

        $divisionId = $divisionResponse->json('division.id');

        $documentResponse = $this->actingAs($admin)
            ->postJson('/api/knowledge-admin/entries', [
                'knowledge_space_id' => $divisionId,
                'title' => 'SOP Ganti Password',
                'summary' => 'Panduan reset password akun kantor.',
                'body' => "1. Verifikasi identitas user.\n2. Reset password di sistem admin.\n3. Minta user login ulang.",
                'scope' => 'internal',
                'type' => 'sop',
                'source_kind' => 'article',
                'access_mode' => 'all',
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('document.knowledge_space_id', $divisionId)
            ->assertJsonPath('document.title', 'SOP Ganti Password');

        $documentId = $documentResponse->json('document.id');
        $document = KnowledgeEntry::query()->findOrFail($documentId);

        $this->assertDatabaseHas('knowledge_spaces', [
            'id' => $divisionId,
            'kind' => 'division',
            'show_in_hub' => true,
        ]);
        $this->assertDatabaseHas('knowledge_sections', [
            'id' => $document->knowledge_section_id,
            'knowledge_space_id' => $divisionId,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/knowledge-admin')
            ->assertOk()
            ->assertJsonPath('general.description', 'Knowledge utama untuk seluruh AI assistant.')
            ->assertJsonPath('general.ai_provider', 'local')
            ->assertJsonPath('general.has_ai_local_api_key', true)
            ->assertJsonCount(1, 'divisions')
            ->assertJsonPath('divisions.0.document_count', 1)
            ->assertJsonPath('divisions.0.documents.0.title', 'SOP Ganti Password');

        $this->assertGreaterThanOrEqual(2, KnowledgeSection::query()->count());
        $this->assertSame('General Knowledge', KnowledgeSpace::query()->where('kind', 'general')->value('name'));
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create([
            'name' => 'Knowledge Admin',
            'email' => 'knowledge.admin@example.com',
        ]);

        $user->syncRoles(['Admin']);

        return $user;
    }
}
