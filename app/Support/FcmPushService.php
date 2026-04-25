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
    private const GENERAL_CHANNEL_ID = 'gesit.general.high_priority.v4';

    private const CALL_CHANNEL_ID = 'gesit.calls.incoming.v4';

    private const ANDROID_NOTIFICATION_SOUND = 'yulie_sekuritas_notifikasi_v2';

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
            $data = $this->notificationData($notification);
            $result = $this->dispatchTokenMessage(
                endpoint: $endpoint,
                accessToken: $accessToken,
                token: (string) $token,
                title: $notification->title,
                body: $notification->message,
                data: $data,
                notificationId: (int) $notification->id,
            );

            if ($result['success']) {
                continue;
            }
        }
    }

    /**
     * @param  array<string, scalar|null>  $data
     * @return array{
     *     success: bool,
     *     invalid_token: bool,
     *     message_name: string|null,
     *     status: int|null,
     *     response_body: mixed
     * }
     */
    public function dispatchDirectMessage(
        string $token,
        string $title,
        string $body,
        array $data = []
    ): array {
        $normalizedToken = trim($token);
        if ($normalizedToken === '') {
            return [
                'success' => false,
                'invalid_token' => false,
                'message_name' => null,
                'status' => null,
                'response_body' => ['error' => 'FCM token is empty.'],
            ];
        }

        $credentials = $this->credentials();
        if ($credentials === null) {
            return [
                'success' => false,
                'invalid_token' => false,
                'message_name' => null,
                'status' => null,
                'response_body' => ['error' => 'FCM credentials are not configured.'],
            ];
        }

        $accessToken = $this->accessToken($credentials);
        if ($accessToken === null) {
            return [
                'success' => false,
                'invalid_token' => false,
                'message_name' => null,
                'status' => null,
                'response_body' => ['error' => 'FCM access token could not be generated.'],
            ];
        }

        $endpoint = sprintf(
            'https://fcm.googleapis.com/v1/projects/%s/messages:send',
            $credentials['project_id']
        );

        return $this->dispatchTokenMessage(
            endpoint: $endpoint,
            accessToken: $accessToken,
            token: $normalizedToken,
            title: trim($title),
            body: trim($body),
            data: $this->normalizeDataPayload($data, $title, $body),
            notificationId: null,
        );
    }

    /**
     * @return array<string, string>
     */
    private function notificationData(Notification $notification): array
    {
        $category = $this->notificationCategory($notification);
        $link = trim((string) ($notification->link ?? ''));
        $data = [
            'notification_id' => (string) $notification->id,
            'id' => (string) $notification->id,
            'title' => $notification->title,
            'message' => $notification->message,
            'type' => (string) $notification->type,
            'link' => $link,
            'created_at' => optional($notification->created_at)?->toISOString() ?? now()->toISOString(),
            'stores_in_center' => 'true',
            'notification_category' => $category,
            'sound' => self::ANDROID_NOTIFICATION_SOUND,
            'full_screen' => $category === 'call' ? 'true' : 'false',
        ];

        $conversationId = $this->conversationIdFromLink($link);
        if ($conversationId !== null) {
            $data['conversation_id'] = $conversationId;
        }

        $callId = $this->callIdFromLink($link);
        if ($callId !== null) {
            $data['call_id'] = $callId;
        }

        return $data;
    }

    private function notificationCategory(Notification $notification): string
    {
        $link = trim((string) ($notification->link ?? ''));

        if ($link !== '' && str_contains($link, 'call=')) {
            return 'call';
        }

        if ($link !== '' && str_contains($link, '/chat/conversations/')) {
            return 'chat';
        }

        if ($link !== '' && str_contains($link, '/helpdesk')) {
            return 'helpdesk';
        }

        if ($link !== '' && str_contains($link, '/feed/posts/')) {
            return 'feed';
        }

        if ($link !== '' && str_contains($link, '/knowledge-hub')) {
            return 'knowledge';
        }

        if ($link !== '' && (str_contains($link, '/submissions/') || str_contains($link, '/form-submissions/'))) {
            return 'approval';
        }

        return 'general';
    }

    private function conversationIdFromLink(string $link): ?string
    {
        if ($link === '') {
            return null;
        }

        if (! preg_match('~/chat/conversations/([^/?#]+)~', $link, $matches)) {
            return null;
        }

        $conversationId = trim((string) ($matches[1] ?? ''));

        return $conversationId !== '' ? $conversationId : null;
    }

    private function callIdFromLink(string $link): ?string
    {
        if ($link === '') {
            return null;
        }

        $query = parse_url($link, PHP_URL_QUERY);
        if (! is_string($query) || trim($query) === '') {
            return null;
        }

        parse_str($query, $parameters);
        $callId = trim((string) ($parameters['call'] ?? ''));

        return $callId !== '' ? $callId : null;
    }

    /**
     * @param  array<string, scalar|null>  $data
     * @return array<string, string>
     */
    private function normalizeDataPayload(array $data, string $title, string $body): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            $normalizedKey = trim((string) $key);
            if ($normalizedKey === '') {
                continue;
            }

            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $normalized[$normalizedKey] = $value === null ? '' : trim((string) $value);
        }

        if (($normalized['title'] ?? '') === '') {
            $normalized['title'] = trim($title);
        }

        if (($normalized['message'] ?? '') === '') {
            $normalized['message'] = trim($body);
        }

        if (($normalized['notification_id'] ?? '') === '' && ($normalized['id'] ?? '') === '') {
            $generatedId = 'debug-'.now()->format('Uu');
            $normalized['notification_id'] = $generatedId;
            $normalized['id'] = $generatedId;
        } elseif (($normalized['notification_id'] ?? '') === '' && ($normalized['id'] ?? '') !== '') {
            $normalized['notification_id'] = $normalized['id'];
        } elseif (($normalized['id'] ?? '') === '' && ($normalized['notification_id'] ?? '') !== '') {
            $normalized['id'] = $normalized['notification_id'];
        }

        if (($normalized['type'] ?? '') === '') {
            $normalized['type'] = 'general';
        }

        if (($normalized['notification_category'] ?? '') === '' && ($normalized['category'] ?? '') === '') {
            $normalized['notification_category'] = 'general';
        }

        if (($normalized['stores_in_center'] ?? '') === '') {
            $normalized['stores_in_center'] = 'false';
        }

        if (($normalized['created_at'] ?? '') === '') {
            $normalized['created_at'] = now()->toISOString();
        }

        if (($normalized['sound'] ?? '') === '') {
            $normalized['sound'] = self::ANDROID_NOTIFICATION_SOUND;
        }

        if (($normalized['full_screen'] ?? '') === '') {
            $normalized['full_screen'] = $this->isCallNotificationData($normalized)
                ? 'true'
                : 'false';
        }

        return $normalized;
    }

    /**
     * @param  array<string, string>  $data
     * @return array{
     *     success: bool,
     *     invalid_token: bool,
     *     message_name: string|null,
     *     status: int|null,
     *     response_body: mixed
     * }
     */
    private function dispatchTokenMessage(
        string $endpoint,
        string $accessToken,
        string $token,
        string $title,
        string $body,
        array $data,
        ?int $notificationId
    ): array {
        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->post($endpoint, [
                    'message' => $this->buildMessagePayload(
                        token: $token,
                        title: $title,
                        body: $body,
                        data: $data,
                    ),
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('FCM push send failed because the endpoint could not be reached.', [
                'notification_id' => $notificationId,
                'token_suffix' => substr($token, -12),
                'message' => $exception->getMessage(),
            ]);

            return [
                'success' => false,
                'invalid_token' => false,
                'message_name' => null,
                'status' => null,
                'response_body' => ['error' => $exception->getMessage()],
            ];
        } catch (\Throwable $exception) {
            Log::warning('FCM push send failed unexpectedly.', [
                'notification_id' => $notificationId,
                'token_suffix' => substr($token, -12),
                'message' => $exception->getMessage(),
            ]);

            return [
                'success' => false,
                'invalid_token' => false,
                'message_name' => null,
                'status' => null,
                'response_body' => ['error' => $exception->getMessage()],
            ];
        }

        if ($response->successful()) {
            return [
                'success' => true,
                'invalid_token' => false,
                'message_name' => filled($response->json('name'))
                    ? (string) $response->json('name')
                    : null,
                'status' => $response->status(),
                'response_body' => $response->json(),
            ];
        }

        $responseBody = $response->json();
        $invalidToken = $this->isInvalidTokenResponse($responseBody);

        if ($invalidToken) {
            NotificationDevice::query()->where('token', $token)->delete();
        } else {
            Log::warning('FCM push send failed.', [
                'notification_id' => $notificationId,
                'token_suffix' => substr($token, -12),
                'status' => $response->status(),
                'body' => $responseBody,
            ]);
        }

        return [
            'success' => false,
            'invalid_token' => $invalidToken,
            'message_name' => null,
            'status' => $response->status(),
            'response_body' => $responseBody,
        ];
    }

    /**
     * @param  array<string, string>  $data
     * @return array<string, mixed>
     */
    private function buildMessagePayload(
        string $token,
        string $title,
        string $body,
        array $data
    ): array {
        $isCallNotification = $this->isCallNotificationData($data);
        $channelId = $isCallNotification ? self::CALL_CHANNEL_ID : self::GENERAL_CHANNEL_ID;
        $messageTag = trim((string) ($data['notification_id'] ?? $data['id'] ?? ''));

        return [
            'token' => $token,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'data' => $data,
            'android' => [
                'priority' => 'high',
                'ttl' => $isCallNotification ? '25s' : '120s',
                'notification' => array_filter([
                    'channel_id' => $channelId,
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    'sound' => self::ANDROID_NOTIFICATION_SOUND,
                    'tag' => $messageTag !== '' ? $messageTag : null,
                ], fn ($value) => $value !== null),
            ],
            'apns' => [
                'headers' => [
                    'apns-priority' => '10',
                    'apns-push-type' => 'alert',
                ],
                'payload' => [
                    'aps' => [
                        'alert' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'sound' => 'default',
                        'interruption-level' => $isCallNotification
                            ? 'time-sensitive'
                            : 'active',
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, string>  $data
     */
    private function isCallNotificationData(array $data): bool
    {
        $category = trim((string) ($data['notification_category'] ?? $data['category'] ?? ''));
        $type = trim((string) ($data['type'] ?? ''));
        $link = trim((string) ($data['link'] ?? ''));

        return $category === 'call'
            || $type === 'chat_call'
            || ($link !== '' && str_contains($link, 'call='));
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
