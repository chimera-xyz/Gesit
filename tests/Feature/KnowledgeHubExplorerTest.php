<?php

namespace Tests\Feature;

use App\Models\KnowledgeSpace;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnowledgeHubExplorerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config()->set('services.zai.api_key', null);
        Storage::fake('public');
    }

    public function test_user_can_create_subfolder_and_upload_to_root_or_subfolder(): void
    {
        $user = $this->makeEmployee();
        $space = $this->makeDivisionSpace('IT');
        $defaultSection = $space->ensureDefaultSection();

        $folderResponse = $this->actingAs($user)
            ->postJson("/api/knowledge-hub/spaces/{$space->id}/folders", [
                'name' => 'SOP Operasional',
                'description' => 'Folder SOP tim IT.',
            ])
            ->assertCreated()
            ->assertJsonPath('folder.name', 'SOP Operasional')
            ->assertJsonPath('folder.is_default', false);

        $folderId = $folderResponse->json('folder.id');

        $this->actingAs($user)
            ->post("/api/knowledge-hub/spaces/{$space->id}/entries", [
                'title' => 'Checklist Laptop Baru',
                'type' => 'onboarding',
                'attachment' => $this->makeTextUpload('laptop-checklist.txt', 'Checklist provisioning laptop baru untuk staff IT.'),
            ])
            ->assertCreated()
            ->assertJsonPath('document.section_is_default', true)
            ->assertJsonPath('document.section_id', $defaultSection->id);

        $this->actingAs($user)
            ->post("/api/knowledge-hub/spaces/{$space->id}/entries", [
                'knowledge_section_id' => $folderId,
                'title' => 'SOP Reset Password',
                'type' => 'sop',
                'attachment' => $this->makeTextUpload('reset-password.txt', 'Reset password email kantor dilakukan melalui service desk internal.'),
            ])
            ->assertCreated()
            ->assertJsonPath('document.section_is_default', false)
            ->assertJsonPath('document.section_id', $folderId)
            ->assertJsonPath('document.path_label', 'IT / SOP Operasional');

        $this->actingAs($user)
            ->getJson('/api/knowledge-hub')
            ->assertOk()
            ->assertJsonPath('spaces.0.name', 'IT')
            ->assertJsonPath('spaces.0.default_section_id', $defaultSection->id)
            ->assertJsonPath('spaces.0.root_entry_count', 1)
            ->assertJsonPath('spaces.0.sections.0.name', 'SOP Operasional')
            ->assertJsonPath('spaces.0.sections.0.entry_count', 1);
    }

    public function test_ai_can_reference_uploaded_attachment_text_from_any_folder(): void
    {
        $user = $this->makeEmployee();
        $space = $this->makeDivisionSpace('IT');

        $folderResponse = $this->actingAs($user)
            ->postJson("/api/knowledge-hub/spaces/{$space->id}/folders", [
                'name' => 'Helpdesk',
            ])
            ->assertCreated();

        $folderId = $folderResponse->json('folder.id');

        $uploadResponse = $this->actingAs($user)
            ->post("/api/knowledge-hub/spaces/{$space->id}/entries", [
                'knowledge_section_id' => $folderId,
                'title' => 'Panduan Umum IT',
                'type' => 'sop',
                'attachment' => $this->makeTextUpload(
                    'panduan-it.txt',
                    'Untuk reset password email kantor, user wajib verifikasi identitas lalu tim service desk IT melakukan reset lewat panel admin.'
                ),
            ])
            ->assertCreated();

        $documentId = $uploadResponse->json('document.id');

        $this->assertDatabaseHas('knowledge_entries', [
            'id' => $documentId,
            'title' => 'Panduan Umum IT',
        ]);

        $askResponse = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'Bagaimana reset password email kantor?',
        ]);

        $askResponse
            ->assertOk()
            ->assertJsonPath('scope', 'internal')
            ->assertJsonFragment([
                'title' => 'Panduan Umum IT',
            ])
            ->assertJsonPath('sources.0.path_label', 'IT / Helpdesk');
    }

    private function makeEmployee(): User
    {
        $user = User::factory()->create([
            'name' => 'Explorer User',
            'email' => 'explorer.user@example.com',
        ]);

        $user->assignRole('Employee');

        return $user;
    }

    private function makeDivisionSpace(string $name): KnowledgeSpace
    {
        return KnowledgeSpace::query()->create([
            'name' => $name,
            'kind' => 'division',
            'description' => "Knowledge {$name}",
            'sort_order' => 1,
            'is_active' => true,
            'show_in_hub' => true,
        ]);
    }

    private function makeTextUpload(string $filename, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'knowledge-hub-');
        file_put_contents($path, $content);

        return new UploadedFile($path, $filename, 'text/plain', null, true);
    }
}
