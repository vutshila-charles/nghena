<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);
        $remember = $credentials['remember'] ?? true;
        unset($credentials['remember']);

        if (! Auth::attempt($credentials, $remember)) {
            throw new ApiException('invalid_credentials', 'The provided credentials are incorrect.', 422);
        }

        $request->session()->regenerate();
        $user = $request->user();

        if (! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            throw new ApiException('account_disabled', 'This account is disabled.', 403);
        }

        return response()->json(['data' => ['user' => UserResource::make($user)]]);
    }

    public function issueToken(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);
        $user = User::query()->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw new ApiException('invalid_credentials', 'The provided credentials are incorrect.', 422);
        }

        if (! $user->is_active) {
            throw new ApiException('account_disabled', 'This account is disabled.', 403);
        }

        $token = $user->createToken($data['device_name'], ['*'], now()->addDays(30));

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'expires_at' => $token->accessToken->expires_at?->toISOString(),
                'user' => UserResource::make($user),
            ],
        ], 201);
    }

    public function me(Request $request)
    {
        return response()->json(['data' => ['user' => UserResource::make($request->user())]]);
    }

    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } else {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['data' => ['logged_out' => true]]);
    }

    public function tokens(Request $request)
    {
        return response()->json([
            'data' => $request->user()->tokens()->get(['id', 'name', 'last_used_at', 'expires_at', 'created_at']),
        ]);
    }

    public function revokeToken(Request $request, int $tokenId)
    {
        $deleted = $request->user()->tokens()->whereKey($tokenId)->delete();

        if (! $deleted) {
            throw new ApiException('token_not_found', 'The requested token does not exist.', 404);
        }

        return response()->json(['data' => ['revoked' => true]]);
    }
}
