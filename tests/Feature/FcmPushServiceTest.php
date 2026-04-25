<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\NotificationDevice;
use App\Models\User;
use App\Support\FcmPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FcmPushServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_general_notification_payload_keeps_notification_block_and_custom_android_channel(): void
    {
        Cache::flush();
        config()->set('services.fcm.enabled', true);
        config()->set('services.fcm.project_id', 'gesit-test-project');
        config()->set('services.fcm.service_account_json', json_encode([
            'project_id' => 'gesit-test-project',
            'client_email' => 'firebase-adminsdk@example.iam.gserviceaccount.com',
            'private_key' => $this->generateTestPrivateKey(),
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR));

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'test-access-token',
            ], 200),
            'https://fcm.googleapis.com/v1/projects/gesit-test-project/messages:send' => Http::response([
                'name' => 'projects/gesit-test-project/messages/abc123',
            ], 200),
        ]);

        $user = User::factory()->create();
        NotificationDevice::query()->create([
            'user_id' => $user->id,
            'token' => 'fcm-token-general-001',
            'platform' => 'android',
            'is_active' => true,
            'last_registered_at' => now(),
        ]);

        $notification = Notification::withoutEvents(fn () => Notification::create([
            'user_id' => $user->id,
            'title' => 'Notif background',
            'message' => 'Harus tampil saat app ditutup.',
            'type' => 'approval_needed',
            'link' => '/submissions/44',
            'is_read' => false,
        ]));

        app(FcmPushService::class)->dispatchNotification($notification);

        Http::assertSentCount(2);
        Http::assertSent(function ($request) use ($notification) {
            return $request->url() === 'https://fcm.googleapis.com/v1/projects/gesit-test-project/messages:send'
                && $request->hasHeader('Authorization', 'Bearer test-access-token')
                && data_get($request->data(), 'message.notification.title') === 'Notif background'
                && data_get($request->data(), 'message.notification.body') === 'Harus tampil saat app ditutup.'
                && data_get($request->data(), 'message.data.notification_id') === (string) $notification->id
                && data_get($request->data(), 'message.data.link') === '/submissions/44'
                && data_get($request->data(), 'message.android.priority') === 'high'
                && data_get($request->data(), 'message.android.ttl') === '120s'
                && data_get($request->data(), 'message.android.notification.channel_id') === 'gesit.general.high_priority.v4'
                && data_get($request->data(), 'message.android.notification.sound') === 'yulie_sekuritas_notifikasi_v2'
                && data_get($request->data(), 'message.android.notification.click_action') === 'FLUTTER_NOTIFICATION_CLICK';
        });
    }

    public function test_call_notification_payload_uses_call_channel_and_short_ttl(): void
    {
        Cache::flush();
        config()->set('services.fcm.enabled', true);
        config()->set('services.fcm.project_id', 'gesit-test-project');
        config()->set('services.fcm.service_account_json', json_encode([
            'project_id' => 'gesit-test-project',
            'client_email' => 'firebase-adminsdk@example.iam.gserviceaccount.com',
            'private_key' => $this->generateTestPrivateKey(),
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR));

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'test-access-token',
            ], 200),
            'https://fcm.googleapis.com/v1/projects/gesit-test-project/messages:send' => Http::response([
                'name' => 'projects/gesit-test-project/messages/call456',
            ], 200),
        ]);

        $result = app(FcmPushService::class)->dispatchDirectMessage(
            token: 'fcm-token-call-001',
            title: 'Panggilan masuk',
            body: 'Ada panggilan baru dari tim IT.',
            data: [
                'notification_id' => 'debug-call-1',
                'id' => 'debug-call-1',
                'type' => 'chat_call',
                'link' => '/chat/conversations/room-1?call=call-1',
                'notification_category' => 'call',
                'stores_in_center' => 'false',
            ],
        );

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://fcm.googleapis.com/v1/projects/gesit-test-project/messages:send'
                && data_get($request->data(), 'message.android.ttl') === '25s'
                && data_get($request->data(), 'message.android.notification.channel_id') === 'gesit.calls.incoming.v4'
                && data_get($request->data(), 'message.data.full_screen') === 'true';
        });
    }

    private function generateTestPrivateKey(): string
    {
        $resource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        $this->assertNotFalse($resource);

        $privateKey = '';
        $exported = openssl_pkey_export($resource, $privateKey);
        if (is_resource($resource) || $resource instanceof \OpenSSLAsymmetricKey) {
            openssl_pkey_free($resource);
        }

        $this->assertTrue($exported);
        $this->assertNotSame('', $privateKey);

        return $privateKey;
    }
}
