<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use App\Support\FcmPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_token_can_be_registered_and_unregistered(): void
    {
        $user = User::factory()->create();
        $token = 'fcm-device-token-001';

        $this->actingAs($user)
            ->postJson('/api/notifications/devices', [
                'token' => $token,
                'platform' => 'android',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('device.platform', 'android');

        $this->assertDatabaseHas('notification_devices', [
            'user_id' => $user->id,
            'token' => $token,
            'platform' => 'android',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->deleteJson('/api/notifications/devices', [
                'token' => $token,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('notification_devices', [
            'token' => $token,
        ]);
    }

    public function test_stream_endpoint_emits_notifications_after_the_given_cursor(): void
    {
        $user = User::factory()->create();

        $existingNotification = Notification::create([
            'user_id' => $user->id,
            'title' => 'Notif lama',
            'message' => 'Sudah ada sebelumnya.',
            'type' => 'general',
            'link' => '/submissions/10',
            'is_read' => false,
        ]);

        $latestNotification = Notification::create([
            'user_id' => $user->id,
            'title' => 'Notif terbaru',
            'message' => 'Harus masuk ke stream.',
            'type' => 'approval_needed',
            'link' => '/submissions/11',
            'is_read' => false,
        ]);

        $response = $this->actingAs($user)
            ->get("/api/notifications/stream?after_id={$existingNotification->id}&timeout_seconds=1");

        $response->assertOk()->assertStreamed();

        $content = $response->streamedContent();

        $this->assertStringContainsString('event: notifications', $content);
        $this->assertStringContainsString(sprintf('id: %d', $latestNotification->id), $content);
        $this->assertStringContainsString('"title":"Notif terbaru"', $content);
        $this->assertStringContainsString(
            sprintf('"last_notification_id":%d', $latestNotification->id),
            $content
        );
    }

    public function test_delete_all_notifications_only_removes_the_current_users_records(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Notification::create([
            'user_id' => $user->id,
            'title' => 'Notif 1',
            'message' => 'Untuk user aktif.',
            'type' => 'general',
            'link' => '/submissions/21',
            'is_read' => false,
        ]);

        Notification::create([
            'user_id' => $user->id,
            'title' => 'Notif 2',
            'message' => 'Untuk user aktif juga.',
            'type' => 'status_changed',
            'link' => '/submissions/22',
            'is_read' => false,
        ]);

        Notification::create([
            'user_id' => $otherUser->id,
            'title' => 'Notif user lain',
            'message' => 'Harus tetap ada.',
            'type' => 'general',
            'link' => '/submissions/23',
            'is_read' => false,
        ]);

        $this->actingAs($user)
            ->deleteJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('unread_count', 0);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $user->id,
            'title' => 'Notif 1',
        ]);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $user->id,
            'title' => 'Notif 2',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $otherUser->id,
            'title' => 'Notif user lain',
        ]);
    }

    public function test_notification_creation_dispatches_the_push_service(): void
    {
        $user = User::factory()->create();

        $service = Mockery::mock(FcmPushService::class);
        $service->shouldReceive('dispatchNotification')
            ->once()
            ->with(Mockery::on(function (Notification $notification) use ($user): bool {
                return $notification->user_id === $user->id
                    && $notification->title === 'Push observer';
            }));

        $this->app->instance(FcmPushService::class, $service);

        Notification::create([
            'user_id' => $user->id,
            'title' => 'Push observer',
            'message' => 'Observer harus memicu pengiriman push.',
            'type' => 'general',
            'link' => '/submissions/99',
            'is_read' => false,
        ]);
    }
}
