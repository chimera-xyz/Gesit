<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\MobileAuthToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $user = User::where('email', trim((string) $request->email))->first();

        if (!$user || !Hash::check((string) $request->password, $user->password)) {
            Log::warning('Failed login attempt', [
                'email' => $request->email,
                'user_found' => (bool) $user,
            ]);

            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Email atau password salah'
            ], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'error' => 'Account disabled',
                'message' => 'Akun Anda sudah dinonaktifkan. Hubungi admin untuk bantuan lebih lanjut.',
            ], 403);
        }

        Auth::login($user, (bool) $request->boolean('remember'));

        $request->session()->regenerate();

        return $this->authenticatedResponse($request->user());
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $user->assignRole('Employee');
        Auth::login($user);
        $request->session()->regenerate();

        return $this->authenticatedResponse($user, 201);
    }

    public function enrollBiometric(Request $request)
    {
        $validated = $request->validate([
            'device_id' => ['required', 'string', 'max:120'],
            'device_name' => ['required', 'string', 'max:120'],
            'platform' => ['required', 'string', 'max:40'],
        ]);

        [$plainTextToken, $mobileAuthToken] = $this->issueMobileAuthToken(
            $request->user(),
            $validated['device_id'],
            $validated['device_name'],
            $validated['platform'],
        );

        return response()->json([
            'message' => 'Biometric login berhasil diaktifkan untuk perangkat ini.',
            'biometric_token' => $plainTextToken,
            'device_id' => $mobileAuthToken->device_id,
            'expires_at' => optional($mobileAuthToken->expires_at)?->toISOString(),
        ], 201);
    }

    public function biometricLogin(Request $request)
    {
        $validated = $request->validate([
            'biometric_token' => ['required', 'string', 'max:255'],
        ]);

        $mobileAuthToken = MobileAuthToken::query()
            ->where('token_hash', hash('sha256', trim($validated['biometric_token'])))
            ->whereNull('revoked_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->with('user.roles')
            ->first();

        if (! $mobileAuthToken || ! $mobileAuthToken->user) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Token login fingerprint sudah tidak valid.',
            ], 401);
        }

        $user = $mobileAuthToken->user;

        if (! $user->is_active) {
            return response()->json([
                'error' => 'Account disabled',
                'message' => 'Akun Anda sudah dinonaktifkan. Hubungi admin untuk bantuan lebih lanjut.',
            ], 403);
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        [$nextPlainTextToken, $nextTokenModel] = $this->issueMobileAuthToken(
            $user,
            $mobileAuthToken->device_id,
            $mobileAuthToken->device_name,
            $mobileAuthToken->platform,
        );

        $nextTokenModel->forceFill([
            'last_used_at' => now(),
            'revoked_at' => null,
        ])->save();

        return $this->authenticatedResponse($user, 200, [
            'biometric_token' => $nextPlainTextToken,
            'biometric_expires_at' => optional($nextTokenModel->expires_at)?->toISOString(),
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Successfully logged out'
        ]);
    }

    private function authenticatedResponse(
        User $user,
        int $status = 200,
        array $extra = [],
    )
    {
        $user->load('roles');

        return response()->json([
            'user' => $user,
            'roles' => $user->roles->pluck('name')->values(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
            ...$extra,
        ], $status);
    }

    private function issueMobileAuthToken(
        User $user,
        string $deviceId,
        string $deviceName,
        string $platform,
    ): array {
        $plainTextToken = Str::random(80);
        $tokenHash = hash('sha256', $plainTextToken);
        $expiresAt = now()->addMonths(6);

        $mobileAuthToken = MobileAuthToken::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'device_id' => trim($deviceId),
            ],
            [
                'device_name' => trim($deviceName),
                'platform' => trim($platform),
                'token_hash' => $tokenHash,
                'last_used_at' => now(),
                'expires_at' => $expiresAt,
                'revoked_at' => null,
            ],
        );

        return [$plainTextToken, $mobileAuthToken];
    }
}
