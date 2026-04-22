<?php

namespace Tests\Feature;

use App\Models\PortalAuthorizationCode;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class PortalAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config()->set('portal.apps.inventaris.client_secret', 'inventaris-secret');
        config()->set('portal.apps.inventaris.launch_url', 'https://inventaris.example.test/login');
        config()->set('portal.apps.inventaris.redirect_uris', [
            'https://inventaris.example.test/auth/sso/callback',
        ]);
    }

    public function test_login_response_contains_portal_payload(): void
    {
        $user = User::factory()->create([
            'email' => 'portal.user@example.com',
            'password' => bcrypt('password123'),
        ]);
        $user->assignRole('Employee');

        $response = $this->postJson('/api/auth/login', [
            'email' => 'portal.user@example.com',
            'password' => 'password123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('portal.home_app', 'gesit')
            ->assertJsonPath('portal.apps.0.key', 'gesit')
            ->assertJsonPath('portal.post_login_path', '/');
    }

    public function test_inventory_client_can_authorize_and_exchange_portal_code(): void
    {
        $user = User::factory()->create([
            'email' => 'inventory.admin@inventaris.com',
            'allowed_apps' => ['inventaris', 'gesit'],
            'home_app' => 'inventaris',
        ]);
        $user->assignRole('Employee');

        $authorizeResponse = $this->actingAs($user)->get('/portal/authorize?' . http_build_query([
            'client' => 'inventaris',
            'redirect_uri' => 'https://inventaris.example.test/auth/sso/callback',
            'state' => 'state-123',
        ]));

        $authorizeResponse->assertRedirect();

        $location = $authorizeResponse->headers->get('Location');
        $this->assertIsString($location);
        $this->assertStringContainsString('state=state-123', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertNotEmpty($query['code'] ?? null);
        $this->assertDatabaseHas('portal_authorization_codes', [
            'client_key' => 'inventaris',
            'user_id' => $user->id,
        ]);

        $exchangeResponse = $this->postJson('/portal/token', [
            'client' => 'inventaris',
            'client_secret' => 'inventaris-secret',
            'code' => $query['code'],
            'redirect_uri' => 'https://inventaris.example.test/auth/sso/callback',
        ]);

        $exchangeResponse
            ->assertOk()
            ->assertJsonPath('user.email', 'inventory.admin@inventaris.com')
            ->assertJsonPath('portal.home_app', 'inventaris')
            ->assertJsonPath('portal.post_login_path', '/portal/apps/inventaris/launch');

        $authCode = PortalAuthorizationCode::query()->firstOrFail();
        $this->assertNotNull($authCode->used_at);
    }

    public function test_inventory_launch_redirects_to_client_login_entry(): void
    {
        $user = User::factory()->create([
            'email' => 'inventory.viewer@inventaris.com',
            'allowed_apps' => ['inventaris'],
            'home_app' => 'inventaris',
        ]);
        $user->assignRole('Employee');

        $response = $this->actingAs($user)->get('/portal/apps/inventaris/launch');

        $response->assertRedirect('https://inventaris.example.test/login');
    }

    public function test_portal_logout_clears_session_and_redirects_to_login(): void
    {
        $user = User::factory()->create([
            'email' => 'inventory.logout@inventaris.com',
        ]);
        $user->assignRole('Employee');

        $response = $this
            ->actingAs($user)
            ->withSession(['portal_logout_probe' => 'active'])
            ->get('/portal/logout');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertFalse(Session::has('portal_logout_probe'));
    }

    public function test_login_page_disables_browser_cache(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertHeader('Pragma', 'no-cache');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
    }
}
