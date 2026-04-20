<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileBiometricAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_user_can_enroll_and_reuse_mobile_biometric_token(): void
    {
        $user = User::factory()->create([
            'name' => 'Raihan Mobile',
            'email' => 'raihan.mobile@example.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);
        $user->assignRole('IT Staff');

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'raihan.mobile@example.com',
            'password' => 'password123',
            'remember' => true,
        ])->assertOk();

        $this->assertNotEmpty($loginResponse->headers->getCookies());

        $enrollResponse = $this->actingAs($user)->postJson('/api/auth/biometric-enroll', [
            'device_id' => 'android-test-device',
            'device_name' => 'GESIT Android',
            'platform' => 'android',
        ])->assertCreated();

        $biometricToken = $enrollResponse->json('biometric_token');

        $this->assertIsString($biometricToken);
        $this->assertNotSame('', trim($biometricToken));

        $biometricLoginResponse = $this->postJson('/api/auth/biometric-login', [
            'biometric_token' => $biometricToken,
        ])->assertOk();

        $biometricLoginResponse
            ->assertJsonPath('user.email', 'raihan.mobile@example.com')
            ->assertJsonPath('roles.0', 'IT Staff');

        $this->assertIsString($biometricLoginResponse->json('biometric_token'));
        $this->assertNotSame($biometricToken, $biometricLoginResponse->json('biometric_token'));
    }
}
