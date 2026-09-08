<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * AuthController — authentication for the SPA + API (Laravel Sanctum).
 *
 * Two modes:
 *  - SPA (stateful): CSRF cookie + session cookie (recommended for the React frontend).
 *  - Bearer token: a `personal_access_token` issued on login (for clients/scripts).
 *
 * Auth identity comes from the live `users` table: `username`, `role`
 * enum('admin','user'), and `is_active`. Inactive users cannot log in.
 */
class AuthController extends Controller
{
    /**
     * SPA: return a Sanctorum CSRF cookie.
     */
    public function csrfCookie(Request $request): JsonResponse
    {
        return response()->json(['message' => 'CSRF cookie set']);
    }

    /**
     * Login using username (+ email fallback) and password.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where(function ($q) use ($data) {
            $q->where('username', $data['username'])
              // allow logging in with email too, if provided
              ->orWhere('email', $data['username']);
        })->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'username' => ['This account is deactivated. Contact the administrator.'],
            ]);
        }

        // SPA: authenticate the web session (Sanctum statefulApi).
        Auth::login($user);

        // Also issue a bearer token for API clients (optional, convenience).
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'user'    => $this->userPayload($user),
            'token'   => $token,
        ]);
    }

    /**
     * Logout: revoke all tokens for this session and forget the web auth.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            $user->tokens()->delete();
        }
        // Revoke the access token used for this request (bearer mode).
        // Parse "id|plain" directly — reliable regardless of guard resolution.
        if ($bearer = $request->bearerToken()) {
            \Laravel\Sanctum\PersonalAccessToken::where('id', (int) strtok($bearer, '|'))->delete();
        } elseif ($request->user()?->currentAccessToken() instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $request->user()->currentAccessToken()->delete();
        }

        // Log out the web/session guard only (Sanctum's guard has no logout()).
        auth('web')->logout();

        // Invalidate the session and regenerate the CSRF token.
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * Return the authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    /**
     * Build a safe user payload (never exposes the password hash).
     */
    private function userPayload(User $user): array
    {
        return [
            'id'         => $user->id,
            'username'   => $user->username,
            'full_name'  => $user->full_name,
            'email'      => $user->email,
            'role'       => $user->role,
            'is_active'  => (bool) $user->is_active,
            'created_at' => $user->created_at,
        ];
    }
}