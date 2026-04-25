<?php

use App\Models\Notification;
use App\Models\NotificationDevice;
use App\Models\User;
use App\Support\FcmPushService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command(
    'notifications:test-push {userId : The target user ID} {--title=GESIT Test Notification} {--message=Push FCM test dari backend GESIT.} {--type=general} {--link=/profile}',
    function () {
        $userId = (int) $this->argument('userId');
        $user = User::query()->findOrFail($userId);
        $type = (string) $this->option('type');

        if (! in_array($type, ['form_submitted', 'approval_needed', 'status_changed', 'signature_required', 'general'], true)) {
            $this->error('Invalid notification type.');

            return self::FAILURE;
        }

        $deviceCount = $user->notificationDevices()
            ->where('is_active', true)
            ->count();

        $notification = Notification::create([
            'user_id' => $user->id,
            'title' => (string) $this->option('title'),
            'message' => (string) $this->option('message'),
            'type' => $type,
            'link' => (string) $this->option('link'),
            'is_read' => false,
        ]);

        $this->info(sprintf(
            'Notification %d created for user %d (%s). Active devices: %d.',
            $notification->id,
            $user->id,
            $user->email,
            $deviceCount
        ));

        if ($deviceCount === 0) {
            $this->warn('No active notification device tokens are registered for this user yet.');
        } else {
            $this->info('FCM dispatch has been triggered through the notification observer.');
        }

        return self::SUCCESS;
    }
)->purpose('Create a notification that also triggers FCM delivery for a user');

Artisan::command(
    'notifications:test-token {token? : The raw FCM token. When omitted, the latest active device token is used.} {--title=GESIT Direct Token Test} {--message=Tes push FCM langsung ke token perangkat.} {--type=general} {--link=/profile} {--category=general}',
    function () {
        $type = trim((string) $this->option('type'));
        if (! in_array($type, ['form_submitted', 'approval_needed', 'status_changed', 'signature_required', 'general', 'chat_call'], true)) {
            $this->error('Invalid notification type.');

            return self::FAILURE;
        }

        $category = trim(strtolower((string) $this->option('category')));
        if (! in_array($category, ['general', 'approval', 'chat', 'call', 'feed', 'helpdesk', 'knowledge'], true)) {
            $this->error('Invalid notification category.');

            return self::FAILURE;
        }

        $requestedToken = trim((string) ($this->argument('token') ?? ''));
        $device = $requestedToken !== ''
            ? NotificationDevice::query()
                ->where('token', $requestedToken)
                ->first()
            : NotificationDevice::query()
                ->where('is_active', true)
                ->orderByDesc('last_registered_at')
                ->orderByDesc('id')
                ->first();

        $token = $requestedToken !== ''
            ? $requestedToken
            : trim((string) ($device?->token ?? ''));

        if ($token === '') {
            $this->error('No active device token is available.');

            return self::FAILURE;
        }

        $notificationId = 'debug-'.now()->format('Uu');
        $result = app(FcmPushService::class)->dispatchDirectMessage(
            token: $token,
            title: trim((string) $this->option('title')),
            body: trim((string) $this->option('message')),
            data: [
                'notification_id' => $notificationId,
                'id' => $notificationId,
                'type' => $type,
                'link' => trim((string) $this->option('link')),
                'created_at' => now()->toISOString(),
                'stores_in_center' => 'false',
                'notification_category' => $category,
                'sound' => 'yulie_sekuritas_notifikasi_v2',
                'full_screen' => $category === 'call' ? 'true' : 'false',
            ],
        );

        $this->line(sprintf(
            'Target token suffix: %s',
            substr($token, -12)
        ));

        if ($device !== null) {
            $this->line(sprintf(
                'Matched device #%d for user %d on %s.',
                $device->id,
                $device->user_id,
                $device->platform
            ));
        } else {
            $this->warn('Token is not stored in notification_devices; sending directly anyway.');
        }

        if ($result['success']) {
            $this->info('FCM accepted the notification request.');

            if (filled($result['message_name'])) {
                $this->line('Message name: '.$result['message_name']);
            }

            return self::SUCCESS;
        }

        if ($result['invalid_token']) {
            $this->warn('FCM rejected the token as invalid or unregistered.');

            return self::FAILURE;
        }

        $this->error('FCM request failed.');

        if ($result['status'] !== null) {
            $this->line('HTTP status: '.$result['status']);
        }

        if (! empty($result['response_body'])) {
            $this->line('Response: '.json_encode($result['response_body'], JSON_UNESCAPED_SLASHES));
        }

        return self::FAILURE;
    }
)->purpose('Send a direct FCM test notification to a specific device token');
