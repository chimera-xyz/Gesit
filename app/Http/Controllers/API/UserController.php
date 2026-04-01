<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    /**
     * Get current authenticated user
     */
    public function currentUser()
    {
        try {
            $user = auth()->user();

            return response()->json([
                'user' => $user,
                'roles' => $user->roles->pluck('name'),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ]);
        } catch (\Exception $e) {
            Log::error('Get Current User Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get user profile
     */
    public function profile()
    {
        try {
            $user = auth()->user();

            return response()->json([
                'user' => $user,
            ]);
        } catch (\Exception $e) {
            Log::error('Get Profile Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update user profile
     */
    public function update(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|email|unique:users,email,' . auth()->id(),
                'employee_id' => 'sometimes|string|max:50|unique:users,employee_id,' . auth()->id(),
                'department' => 'sometimes|string|max:255',
                'phone_number' => 'sometimes|string|max:20',
            ]);

            $user = auth()->user();
            $user->update($validated);

            return response()->json([
                'success' => true,
                'user' => $user,
            ]);
        } catch (\Exception $e) {
            Log::error('Update Profile Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Change password
     */
    public function changePassword(Request $request)
    {
        try {
            $validated = $request->validate([
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:8|confirmed',
                'new_password_confirmation' => 'required|string|min:8',
            ]);

            $user = auth()->user();

            // Check current password
            if (!Hash::check($validated['current_password'], $user->password)) {
                return response()->json([
                    'error' => 'Current password is incorrect',
                ], 422);
            }

            // Update password
            $user->password = Hash::make($validated['new_password']);
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Password changed successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Change Password Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}