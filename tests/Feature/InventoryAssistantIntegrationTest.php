<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InventoryAssistantIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config()->set('services.zai.api_key', null);
        config()->set('services.inventory.base_url', 'https://inventory.internal');
        config()->set('services.inventory.assistant_secret', 'inventory-shared-secret');
    }

    public function test_inventory_question_uses_live_inventory_provider(): void
    {
        Http::fake([
            'https://inventory.internal/api/internal/assistant/inventory/query' => Http::response([
                'summary' => [
                    'search_label' => 'monitor',
                    'matched_total' => 2,
                    'available_count' => 1,
                    'in_use_count' => 1,
                    'maintenance_count' => 0,
                    'broken_count' => 0,
                    'lost_count' => 0,
                    'locations' => ['Gudang IT', 'Ruang Operasional'],
                ],
                'items' => [
                    [
                        'unique_code' => 'MON-2026-0001',
                        'name' => 'Monitor Dell 24',
                        'category_name' => 'Monitor',
                        'status' => 'available',
                        'status_label' => 'Tersedia',
                        'location' => 'Gudang IT',
                        'detail_url' => 'https://inventaris.example.test/scan/MON-2026-0001',
                        'latest_history' => [
                            'title' => 'Relokasi ke Gudang IT',
                        ],
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create([
            'allowed_apps' => ['gesit', 'inventaris'],
            'home_app' => 'gesit',
        ]);
        $user->assignRole('Employee');

        $response = $this->actingAs($user)->postJson('/api/knowledge-hub/ask', [
            'question' => 'coy ada monitor kosong ga di inventory?',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('scope', 'inventory')
            ->assertJsonPath('provider', 'inventory')
            ->assertJsonPath('assistant_message.scopeLabel', 'Inventaris IT')
            ->assertJsonPath('assistant_message.sources.0.title', 'Monitor Dell 24 (MON-2026-0001)')
            ->assertJsonPath('assistant_message.sources.0.external_url', 'https://inventaris.example.test/scan/MON-2026-0001');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://inventory.internal/api/internal/assistant/inventory/query'
                && $request->hasHeader('X-Gesit-Assistant-Secret', 'inventory-shared-secret');
        });
    }
}
