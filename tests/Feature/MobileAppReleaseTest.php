<?php

namespace Tests\Feature;

use App\Models\MobileAppRelease;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MobileAppReleaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_publish_mobile_app_release(): void
    {
        Storage::fake('local');

        $admin = $this->makeAdminUser();

        $response = $this->actingAs($admin)->post('/api/mobile-app/releases', [
            'platform' => 'android',
            'channel' => 'production',
            'version_name' => '1.4.0',
            'version_code' => 14,
            'minimum_supported_version_code' => 14,
            'force_update' => '1',
            'release_notes' => 'Force update keamanan.',
            'publish_now' => '1',
            'apk_file' => UploadedFile::fake()->create(
                'gesit-release.apk',
                2048,
                'application/vnd.android.package-archive'
            ),
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('release.version_code', 14)
            ->assertJsonPath('release.is_published', true);

        $release = MobileAppRelease::query()->firstOrFail();

        $this->assertSame('android', $release->platform);
        $this->assertSame('production', $release->channel);
        $this->assertSame(14, $release->version_code);
        $this->assertSame(14, $release->minimum_supported_version_code);
        $this->assertTrue($release->is_force_update);
        $this->assertTrue($release->is_published);
        $this->assertNotNull($release->published_at);
        $this->assertDatabaseHas('mobile_app_releases', [
            'id' => $release->id,
            'uploaded_by' => $admin->id,
        ]);
        Storage::disk('local')->assertExists($release->apk_path);
    }

    public function test_latest_endpoint_returns_force_update_when_current_version_is_below_minimum(): void
    {
        $release = MobileAppRelease::query()->create([
            'platform' => 'android',
            'channel' => 'production',
            'version_name' => '1.5.0',
            'version_code' => 15,
            'minimum_supported_version_code' => 15,
            'is_force_update' => true,
            'release_notes' => 'Force update.',
            'apk_path' => 'mobile-app-releases/android/production/gesit-v15.apk',
            'apk_file_name' => 'gesit-v15.apk',
            'apk_mime_type' => 'application/vnd.android.package-archive',
            'file_size' => 123456,
            'sha256' => str_repeat('a', 64),
            'is_published' => true,
            'published_at' => now(),
        ]);

        $response = $this->getJson('/api/mobile-app/releases/latest?platform=android&channel=production&current_version_code=14');

        $response->assertOk()
            ->assertJsonPath('status', 'force_update')
            ->assertJsonPath('release.id', $release->id)
            ->assertJsonPath('release.version_code', 15)
            ->assertJsonPath('release.minimum_supported_version_code', 15);

        $this->assertStringContainsString('/api/mobile-app/releases/'.$release->id.'/download', (string) $response->json('release.download_path'));
    }

    public function test_download_endpoint_accepts_relative_signed_release_url(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(
            'mobile-app-releases/android/production/gesit-v15.apk',
            'fake apk payload',
        );

        MobileAppRelease::query()->create([
            'platform' => 'android',
            'channel' => 'production',
            'version_name' => '1.5.0',
            'version_code' => 15,
            'minimum_supported_version_code' => 15,
            'is_force_update' => true,
            'release_notes' => 'Force update.',
            'apk_path' => 'mobile-app-releases/android/production/gesit-v15.apk',
            'apk_file_name' => 'gesit-v15.apk',
            'apk_mime_type' => 'application/vnd.android.package-archive',
            'file_size' => 16,
            'sha256' => hash('sha256', 'fake apk payload'),
            'is_published' => true,
            'published_at' => now(),
        ]);

        $response = $this->getJson('/api/mobile-app/releases/latest?platform=android&channel=production&current_version_code=14');

        $downloadPath = (string) $response->json('release.download_path');

        $this->get($downloadPath)
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.android.package-archive');
    }

    public function test_latest_endpoint_returns_optional_update_when_current_version_is_still_supported(): void
    {
        MobileAppRelease::query()->create([
            'platform' => 'android',
            'channel' => 'production',
            'version_name' => '1.6.0',
            'version_code' => 16,
            'minimum_supported_version_code' => 15,
            'is_force_update' => false,
            'release_notes' => 'Update opsional.',
            'apk_path' => 'mobile-app-releases/android/production/gesit-v16.apk',
            'apk_file_name' => 'gesit-v16.apk',
            'apk_mime_type' => 'application/vnd.android.package-archive',
            'file_size' => 223456,
            'sha256' => str_repeat('b', 64),
            'is_published' => true,
            'published_at' => now(),
        ]);

        $this->getJson('/api/mobile-app/releases/latest?platform=android&channel=production&current_version_code=15')
            ->assertOk()
            ->assertJsonPath('status', 'optional_update')
            ->assertJsonPath('release.version_code', 16)
            ->assertJsonPath('release.minimum_supported_version_code', 15);
    }

    public function test_latest_endpoint_returns_up_to_date_when_current_version_matches_latest(): void
    {
        MobileAppRelease::query()->create([
            'platform' => 'android',
            'channel' => 'production',
            'version_name' => '1.7.0',
            'version_code' => 17,
            'minimum_supported_version_code' => 16,
            'is_force_update' => false,
            'release_notes' => 'Patch terbaru.',
            'apk_path' => 'mobile-app-releases/android/production/gesit-v17.apk',
            'apk_file_name' => 'gesit-v17.apk',
            'apk_mime_type' => 'application/vnd.android.package-archive',
            'file_size' => 323456,
            'sha256' => str_repeat('c', 64),
            'is_published' => true,
            'published_at' => now(),
        ]);

        $this->getJson('/api/mobile-app/releases/latest?platform=android&channel=production&current_version_code=17')
            ->assertOk()
            ->assertJsonPath('status', 'up_to_date')
            ->assertJsonPath('release.version_code', 17);
    }

    public function test_latest_endpoint_returns_optional_update_when_force_update_is_disabled(): void
    {
        MobileAppRelease::query()->create([
            'platform' => 'android',
            'channel' => 'production',
            'version_name' => '1.8.0',
            'version_code' => 18,
            'minimum_supported_version_code' => 18,
            'is_force_update' => false,
            'release_notes' => 'Update opsional.',
            'apk_path' => 'mobile-app-releases/android/production/gesit-v18.apk',
            'apk_file_name' => 'gesit-v18.apk',
            'apk_mime_type' => 'application/vnd.android.package-archive',
            'file_size' => 423456,
            'sha256' => str_repeat('d', 64),
            'is_published' => true,
            'published_at' => now(),
        ]);

        $this->getJson('/api/mobile-app/releases/latest?platform=android&channel=production&current_version_code=17')
            ->assertOk()
            ->assertJsonPath('status', 'optional_update')
            ->assertJsonPath('release.is_force_update', false)
            ->assertJsonPath('release.update_mode', 'optional');
    }

    private function makeAdminUser(): User
    {
        Role::findOrCreate('Admin', 'web');

        $admin = User::factory()->create([
            'email' => 'admin.mobile.release@example.com',
        ]);
        $admin->assignRole('Admin');

        return $admin;
    }
}
