<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class PortalRegistry
{
    public function appKeys(): array
    {
        return array_values(array_keys((array) config('portal.apps', [])));
    }

    public function appCatalog(): array
    {
        return collect((array) config('portal.apps', []))
            ->map(function (array $app, string $key) {
                return [
                    'key' => $key,
                    'name' => (string) ($app['name'] ?? Str::headline($key)),
                    'description' => (string) ($app['description'] ?? ''),
                    'badge' => filled($app['badge'] ?? null) ? (string) $app['badge'] : null,
                ];
            })
            ->values()
            ->all();
    }

    public function appConfig(string $key): ?array
    {
        $config = config("portal.apps.{$key}");

        return is_array($config) ? $config : null;
    }

    public function hasApp(string $key): bool
    {
        return $this->appConfig($key) !== null;
    }

    public function clientConfig(string $key): ?array
    {
        $config = $this->appConfig($key);

        if (! is_array($config) || $key === 'gesit') {
            return null;
        }

        return $config;
    }

    public function clientLaunchUrl(string $key): ?string
    {
        $client = $this->clientConfig($key);

        if (! is_array($client)) {
            return null;
        }

        $launchUrl = $client['launch_url'] ?? null;

        return is_string($launchUrl) && filled(trim($launchUrl))
            ? trim($launchUrl)
            : null;
    }

    public function normalizeAllowedApps(?array $allowedApps, ?string $email = null): array
    {
        $normalized = collect($allowedApps ?? [])
            ->map(fn ($value) => is_string($value) ? trim($value) : null)
            ->filter(fn (?string $value) => filled($value) && $this->hasApp($value))
            ->unique()
            ->values();

        if ($normalized->isNotEmpty()) {
            return $normalized->all();
        }

        return [$this->inferDefaultAppFromEmail($email)];
    }

    public function inferDefaultAppFromEmail(?string $email): string
    {
        $domain = Str::after((string) Str::lower(trim((string) $email)), '@');
        $mapped = config("portal.email_domain_defaults.{$domain}");

        return is_string($mapped) && $this->hasApp($mapped)
            ? $mapped
            : 'gesit';
    }

    public function resolveHomeApp(?string $homeApp, array $allowedApps, ?string $email = null): string
    {
        $normalized = is_string($homeApp) ? trim($homeApp) : null;

        if ($normalized !== null && in_array($normalized, $allowedApps, true)) {
            return $normalized;
        }

        $inferred = $this->inferDefaultAppFromEmail($email);

        if (in_array($inferred, $allowedApps, true)) {
            return $inferred;
        }

        return $allowedApps[0] ?? 'gesit';
    }

    public function allowedAppsFor(User $user): array
    {
        return $this->normalizeAllowedApps(
            is_array($user->allowed_apps) ? $user->allowed_apps : null,
            $user->email,
        );
    }

    public function homeAppFor(User $user): string
    {
        return $this->resolveHomeApp($user->home_app, $this->allowedAppsFor($user), $user->email);
    }

    public function canAccess(User $user, string $appKey): bool
    {
        return in_array($appKey, $this->allowedAppsFor($user), true);
    }

    public function launchPath(string $appKey): string
    {
        return $appKey === 'gesit'
            ? '/'
            : "/portal/apps/{$appKey}/launch";
    }

    public function launcherItemsFor(User $user): array
    {
        $allowedApps = $this->allowedAppsFor($user);
        $homeApp = $this->resolveHomeApp($user->home_app, $allowedApps, $user->email);

        return collect($this->appCatalog())
            ->filter(fn (array $app) => in_array($app['key'], $allowedApps, true))
            ->map(function (array $app) use ($homeApp) {
                return [
                    ...$app,
                    'is_home' => $app['key'] === $homeApp,
                    'launch_path' => $this->launchPath($app['key']),
                    'open_mode' => $app['key'] === 'gesit' ? 'internal' : 'relay',
                ];
            })
            ->values()
            ->all();
    }

    public function portalPayloadFor(User $user): array
    {
        $allowedApps = $this->allowedAppsFor($user);
        $homeApp = $this->resolveHomeApp($user->home_app, $allowedApps, $user->email);

        return [
            'allowed_apps' => $allowedApps,
            'home_app' => $homeApp,
            'launcher_path' => '/portal',
            'post_login_path' => $this->launchPath($homeApp),
            'apps' => $this->launcherItemsFor($user),
        ];
    }

    public function authPayloadFor(User $user, array $extra = []): array
    {
        $user->loadMissing('roles');

        return [
            'user' => $user,
            'roles' => $user->roles->pluck('name')->values(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
            'portal' => $this->portalPayloadFor($user),
            ...$extra,
        ];
    }

    public function firstRedirectUriForClient(string $clientKey): ?string
    {
        $client = $this->clientConfig($clientKey);

        if (! is_array($client)) {
            return null;
        }

        $redirectUris = Arr::wrap($client['redirect_uris'] ?? []);
        $first = collect($redirectUris)
            ->map(fn ($uri) => is_string($uri) ? trim($uri) : null)
            ->first(fn (?string $uri) => filled($uri));

        return is_string($first) ? $first : null;
    }

    public function matchesClientSecret(string $clientKey, ?string $secret): bool
    {
        $client = $this->clientConfig($clientKey);

        if (! is_array($client)) {
            return false;
        }

        $expectedSecret = (string) ($client['client_secret'] ?? '');

        return $expectedSecret !== ''
            && is_string($secret)
            && hash_equals($expectedSecret, $secret);
    }

    public function isAllowedRedirectUri(string $clientKey, string $redirectUri): bool
    {
        $client = $this->clientConfig($clientKey);

        if (! is_array($client)) {
            return false;
        }

        return collect(Arr::wrap($client['redirect_uris'] ?? []))
            ->map(fn ($uri) => is_string($uri) ? trim($uri) : null)
            ->filter()
            ->contains(trim($redirectUri));
    }
}
