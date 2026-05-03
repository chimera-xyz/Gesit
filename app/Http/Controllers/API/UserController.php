<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PortalRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct(private readonly PortalRegistry $portalRegistry) {}

    /**
     * Get current authenticated user
     */
    public function currentUser()
    {
        try {
            $user = auth()->user();

            return response()->json([
                ...$this->portalRegistry->authPayloadFor($user),
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
            $user->loadMissing('roles');

            return response()->json([
                'user' => $user,
                'portal' => $this->portalRegistry->portalPayloadFor($user),
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
                'employee_id' => 'sometimes|nullable|string|max:50|unique:users,employee_id,' . auth()->id(),
                'department' => 'sometimes|nullable|string|max:255',
                'phone_number' => 'sometimes|nullable|string|max:20',
                'bio' => 'sometimes|nullable|string|max:180',
                'profile_photo' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            ]);

            $user = auth()->user();
            unset($validated['profile_photo']);

            foreach (['employee_id', 'department', 'phone_number', 'bio'] as $field) {
                if (! array_key_exists($field, $validated)) {
                    continue;
                }

                $normalized = trim((string) $validated[$field]);
                $validated[$field] = $normalized === '' ? null : $normalized;
            }

            if ($request->hasFile('profile_photo')) {
                $previousPhotoPath = trim((string) $user->profile_photo_path);
                $validated['profile_photo_path'] = $request
                    ->file('profile_photo')
                    ->store('profile-photos', 'public');

                if ($previousPhotoPath !== '') {
                    Storage::disk('public')->delete($previousPhotoPath);
                }
            }

            if ($validated !== []) {
                $user->update($validated);
            }

            $user->refresh();

            return response()->json([
                'success' => true,
                ...$this->portalRegistry->authPayloadFor($user),
            ]);
        } catch (ValidationException $e) {
            throw $e;
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
