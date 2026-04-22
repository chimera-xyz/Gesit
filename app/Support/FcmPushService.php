<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\NotificationDevice;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmPushService
{
    public function dispatchNotification(Notification $notification): void
    {
        $credentials = $this->credentials();
        if ($credentials === null) {
            return;
        }

        $tokens = NotificationDevice::query()
            ->where('user_id', $notification->user_id)
            ->where('is_active', true)
            ->pluck('token')
            ->filter(fn ($token) => filled($token))
            ->unique()
            ->values();

        if ($tokens->isEmpty()) {
            return;
        }

        $accessToken = $this->accessToken($credentials);
        if ($accessToken === null) {
            return;
        }

        $endpoint = sprintf(
            'https://fcm.googleapis.com/v1/projects/%s/messages:send',
            $credentials['project_id']
        );

        foreach ($tokens as $token) {
            try {
                $response = Http::withToken($accessToken)
                    ->acceptJson()
                    ->connectTimeout(5)
                    ->timeout(10)
                    ->post($endpoint, [
                        'message' => [
                            'token' => $token,
                            'notification' => [
                                'title' => $notification->title,
                                'body' => $notification->message,
                            ],
                            'data' => [
                                'notification_id' => (string) $notification->id,
                                'title' => $notification->title,
                                'message' => $notification->message,
                                'type' => $notification->type,
                                'link' => (string) ($notification->link ?? ''),
                                'created_at' => optional($notification->created_at)?->toISOString() ?? now()->toISOString(),
                                'stores_in_center' => 'true',
                            ],
                            'android' => [
                                'priority' => 'high',
                                'notification' => [
                                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                                ],
                            ],
                            'apns' => [
                                'headers' => [
                                    'apns-priority' => '10',
                                ],
                                'payload' => [
                                    'aps' => [
                                        'sound' => 'default',
                                    ],
                                ],
                            ],
                        ],
                    ]);
            } catch (ConnectionException $exception) {
                Log::warning('FCM push send failed because the endpoint could not be reached.', [
                    'notification_id' => $notification->id,
                    'token_suffix' => substr((string) $token, -12),
                    'message' => $exception->getMessage(),
                ]);

                return;
            } catch (\Throwable $exception) {
                Log::warning('FCM push send failed unexpectedly.', [
                    'notification_id' => $notification->id,
                    'token_suffix' => substr((string) $token, -12),
                    'message' => $exception->getMessage(),
                ]);

                return;
            }

            if ($response->successful()) {
                continue;
            }

            if ($this->isInvalidTokenResponse($response->json())) {
                NotificationDevice::query()->where('token', $token)->delete();
                continue;
            }

            Log::warning('FCM push send failed.', [
                'notification_id' => $notification->id,
                'token_suffix' => substr((string) $token, -12),
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        }
    }

    /**
     * @return array<string, string>|null
     */
    private function credentials(): ?array
    {
        if (! config('services.fcm.enabled')) {
            return null;
        }

        $rawJson = config('services.fcm.service_account_json');
        if (is_string($rawJson) && trim($rawJson) !== '') {
            $decoded = json_decode($rawJson, true);
            if (is_array($decoded)) {
                $normalized = $this->normalizeCredentials($decoded);
                if ($normalized !== null) {
                    return $normalized;
                }

                $this->reportInvalidCredentialsShape($decoded);
            }
        }

        $path = config('services.fcm.service_account_path');
        if (! is_string($path) || trim($path) === '' || ! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return null;
        }

        $normalized = $this->normalizeCredentials($decoded);
        if ($normalized !== null) {
            return $normalized;
        }

        $this->reportInvalidCredentialsShape($decoded);

        return null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, string>|null
     */
    private function normalizeCredentials(array $credentials): ?array
    {
        $clientEmail = trim((string) ($credentials['client_email'] ?? ''));
        $privateKey = trim((string) ($credentials['private_key'] ?? ''));
        $projectId = trim((string) (config('services.fcm.project_id') ?: ($credentials['project_id'] ?? '')));
        $tokenUri = trim((string) ($credentials['token_uri'] ?? config('services.fcm.token_uri')));

        if ($clientEmail === '' || $privateKey === '' || $projectId === '' || $tokenUri === '') {
            return null;
        }

        return [
            'client_email' => $clientEmail,
            'private_key' => $privateKey,
            'project_id' => $projectId,
            'token_uri' => $tokenUri,
        ];
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function accessToken(array $credentials): ?string
    {
        $cacheKey = 'services.fcm.access_token.'.md5($credentials['client_email'].$credentials['project_id']);

        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($credentials) {
            $jwt = $this->serviceAccountJwt($credentials);
            if ($jwt === null) {
                return null;
            }

            try {
                $response = Http::asForm()
                    ->connectTimeout(5)
                    ->timeout(10)
                    ->post($credentials['token_uri'], [
                        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                        'assertion' => $jwt,
                    ]);
            } catch (ConnectionException $exception) {
                Log::warning('FCM token exchange failed because the endpoint could not be reached.', [
                    'message' => $exception->getMessage(),
                ]);

                return null;
            } catch (\Throwable $exception) {
                Log::warning('FCM token exchange failed unexpectedly.', [
                    'message' => $exception->getMessage(),
                ]);

                return null;
            }

            if (! $response->successful()) {
                Log::warning('FCM token exchange failed.', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return null;
            }

            $accessToken = trim((string) $response->json('access_token'));

            return $accessToken !== '' ? $accessToken : null;
        });
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function serviceAccountJwt(array $credentials): ?string
    {
        $issuedAt = time();
        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ]));
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => $credentials['token_uri'],
            'iat' => $issuedAt,
            'exp' => $issuedAt + 3600,
        ]));

        if ($header === null || $claims === null) {
            return null;
        }

        $unsignedToken = $header.'.'.$claims;
        $signature = '';
        $privateKey = openssl_pkey_get_private($credentials['private_key']);
        if ($privateKey === false) {
            Log::warning('FCM private key could not be loaded.');

            return null;
        }

        try {
            $signed = openssl_sign($unsignedToken, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        } finally {
            openssl_pkey_free($privateKey);
        }

        if (! $signed) {
            Log::warning('FCM JWT signing failed.');

            return null;
        }

        return $unsignedToken.'.'.$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string|false $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @param  array<string, mixed>|null  $responseBody
     */
    private function isInvalidTokenResponse(?array $responseBody): bool
    {
        if ($responseBody === null) {
            return false;
        }

        $errorCode = data_get($responseBody, 'error.details.0.errorCode');
        if (in_array($errorCode, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
            return true;
        }

        $status = data_get($responseBody, 'error.status');

        return in_array($status, ['NOT_FOUND', 'INVALID_ARGUMENT'], true);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function reportInvalidCredentialsShape(array $credentials): void
    {
        if (! Cache::add('services.fcm.invalid_credentials_shape_reported', true, now()->addHour())) {
            return;
        }

        $looksLikeClientConfig = array_key_exists('project_info', $credentials)
            || array_key_exists('client', $credentials);

        Log::warning(
            $looksLikeClientConfig
                ? 'FCM service account credentials are missing. google-services.json is valid for Android clients but cannot be used by the backend to send push notifications.'
                : 'FCM credentials are incomplete. Expected a Firebase service account JSON containing client_email and private_key.'
        );
    }
}
