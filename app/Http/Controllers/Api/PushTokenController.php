<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserPushToken;
use Illuminate\Http\Request;

class PushTokenController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'platform' => ['nullable', 'string', 'max:20'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        if (!$user) {
            abort(403);
        }

        $token = UserPushToken::query()->firstOrNew(['token' => $data['token']]);
        $token->user_id = $user->id;
        $token->platform = $data['platform'] ?? $token->platform;
        $token->device_name = $data['device_name'] ?? $token->device_name;
        $token->last_used_at = now();
        $token->save();

        return response()->json(['ok' => true]);
    }
}
