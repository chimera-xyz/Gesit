<?php

namespace App\Http\Controllers;

use App\Models\PortalAuthorizationCode;
use App\Support\PortalRegistry;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PortalController extends Controller
{
    public function __construct(private readonly PortalRegistry $portalRegistry) {}

    public function launch(Request $request, string $app): RedirectResponse
    {
        $user = $request->user();

        if (! $user || ! $this->portalRegistry->canAccess($user, $app)) {
            return redirect('/portal?access_denied=' . urlencode($app));
        }

        if ($app === 'gesit') {
            return redirect('/');
        }

        $launchUrl = $this->portalRegistry->clientLaunchUrl($app);

        if (! filled($launchUrl)) {
            return redirect('/portal?launch_unavailable=' . urlencode($app));
        }

        return redirect()->away($launchUrl);
    }

    public function authorize(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client' => ['required', 'string', 'max:80'],
            'redirect_uri' => ['required', 'url', 'max:500'],
            'state' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $clientKey = trim($validated['client']);
        $redirectUri = trim($validated['redirect_uri']);

        if (! $user || ! $this->portalRegistry->canAccess($user, $clientKey)) {
            return redirect('/portal?access_denied=' . urlencode($clientKey));
        }

        if (! $this->portalRegistry->isAllowedRedirectUri($clientKey, $redirectUri)) {
            throw new HttpResponseException(response()->json([
                'error' => 'Redirect URI tidak valid untuk aplikasi tujuan.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $code = DB::transaction(function () use ($user, $clientKey, $redirectUri) {
            PortalAuthorizationCode::query()
                ->where('user_id', $user->id)
                ->where('client_key', $clientKey)
                ->whereNull('used_at')
                ->delete();

            return PortalAuthorizationCode::query()->create([
                'user_id' => $user->id,
                'client_key' => $clientKey,
                'code' => Str::random(96),
                'redirect_uri' => $redirectUri,
                'expires_at' => now()->addMinutes(2),
            ]);
        });

        $separator = Str::contains($redirectUri, '?') ? '&' : '?';
        $query = [
            'code' => $code->code,
        ];

        if (filled($validated['state'] ?? null)) {
            $query['state'] = trim((string) $validated['state']);
        }

        return redirect()->away($redirectUri . $separator . http_build_query($query));
    }

    public function token(Request $request)
    {
        $validated = $request->validate([
            'client' => ['required', 'string', 'max:80'],
            'client_secret' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:120'],
            'redirect_uri' => ['required', 'url', 'max:500'],
        ]);

        $clientKey = trim($validated['client']);

        if (! $this->portalRegistry->matchesClientSecret($clientKey, $validated['client_secret'])) {
            return response()->json([
                'error' => 'Client credentials tidak valid.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $authCode = PortalAuthorizationCode::query()
            ->with('user.roles')
            ->where('client_key', $clientKey)
            ->where('code', trim($validated['code']))
            ->where('redirect_uri', trim($validated['redirect_uri']))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $authCode || ! $authCode->user || ! $authCode->user->is_active) {
            return response()->json([
                'error' => 'Authorization code tidak valid atau sudah kedaluwarsa.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $authCode->forceFill([
            'used_at' => now(),
        ])->save();

        return response()->json([
            ...$this->portalRegistry->authPayloadFor($authCode->user),
            'token_type' => 'authorization_code',
            'expires_in' => 120,
        ]);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
