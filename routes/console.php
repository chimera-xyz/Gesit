<?php

use App\Models\Notification;
use App\Models\User;
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
