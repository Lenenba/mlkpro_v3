<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ApiTokenController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'type' => 'required|in:public,private',
            'expires_at' => 'nullable|date',
        ]);

        $abilities = $data['type'] === 'public'
            ? ['inventory:read', 'exports:read']
            : ['inventory:read', 'inventory:write', 'exports:read'];

        $expiresAt = $data['expires_at'] ? Carbon::parse($data['expires_at']) : null;
        if ($expiresAt && $expiresAt->isPast()) {
            throw ValidationException::withMessages([
                'expires_at' => ['Expiration date must be in the future.'],
            ]);
        }

        $token = $user->createToken($data['name'], $abilities, $expiresAt);

        return response()->json([
            'token' => [
                'id' => $token->accessToken->id,
                'name' => $token->accessToken->name,
                'abilities' => $token->accessToken->abilities,
                'expires_at' => $token->accessToken->expires_at,
            ],
            'plain_text_token' => $token->plainTextToken,
        ], 201);
    }

    public function destroy(Request $request, string $tokenId)
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $token = $user->tokens()->whereKey($tokenId)->firstOrFail();
        $token->delete();

        return response()->json([
            'message' => 'Token revoked.',
        ]);
    }
}
