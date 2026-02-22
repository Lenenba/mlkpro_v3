<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\AccountDeletionService;
use App\Utils\FileHandler;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request)
    {
        return $this->inertiaOrJson('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone_number' => $validated['phone_number'] ?? null,
        ]);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $defaultAvatar = config('icon_presets.defaults.avatar');
        if ($request->hasFile('profile_picture')) {
            $user->profile_picture = FileHandler::handleImageUpload(
                'team',
                $request,
                'profile_picture',
                $defaultAvatar,
                $user->profile_picture
            );
        } elseif (!empty($validated['avatar_icon'])) {
            if (
                $user->profile_picture
                && $user->profile_picture !== $validated['avatar_icon']
                && !str_starts_with($user->profile_picture, '/')
                && !str_starts_with($user->profile_picture, 'http://')
                && !str_starts_with($user->profile_picture, 'https://')
                && $user->profile_picture !== $defaultAvatar
            ) {
                FileHandler::deleteFile($user->profile_picture, $defaultAvatar);
            }
            $user->profile_picture = $validated['avatar_icon'];
        }

        $user->save();

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Profile updated.',
                'user' => $user->fresh(),
            ]);
        }

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request, AccountDeletionService $accountDeletion)
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        try {
            if ($user->isAccountOwner()) {
                $accountDeletion->deleteAccount($user);
            } else {
                $accountDeletion->deleteUser($user);
            }
        } catch (\Throwable $exception) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Unable to delete account right now. Please contact support.',
                ], 500);
            }

            return Redirect::back()->with('error', 'Unable to delete account right now. Please contact support.');
        }

        Auth::logout();

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Account deleted.',
            ]);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
