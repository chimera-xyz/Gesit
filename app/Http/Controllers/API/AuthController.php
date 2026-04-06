<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

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

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Successfully logged out'
        ]);
    }

    private function authenticatedResponse(User $user, int $status = 200)
    {
        $user->load('roles');

        return response()->json([
            'user' => $user,
            'roles' => $user->roles->pluck('name')->values(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        ], $status);
    }
}
