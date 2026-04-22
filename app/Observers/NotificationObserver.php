<?php

namespace App\Observers;

use App\Models\Notification;
use App\Support\FcmPushService;

class NotificationObserver
{
    public function created(Notification $notification): void
    {
        if (app()->runningUnitTests() || app()->runningInConsole()) {
            app(FcmPushService::class)->dispatchNotification($notification->fresh() ?? $notification);

            return;
        }

        $notificationId = (int) $notification->id;

        dispatch(function () use ($notificationId): void {
            $freshNotification = Notification::query()->find($notificationId);
            if ($freshNotification === null) {
                return;
            }

            app(FcmPushService::class)->dispatchNotification($freshNotification);
        })->afterResponse();
    }
}
