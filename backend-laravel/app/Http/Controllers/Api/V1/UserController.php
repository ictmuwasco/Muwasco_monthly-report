<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * UserController — user management API (admin only for mutations).
 *
 * Controllers stay thin: authorize (policy), validate (form request),
 * call the model, log via AuditLogger, return a response.
 */
class UserController extends Controller
{
    /**
     * List users with optional search + role filter (paginated).
     */
    public function index(Request $request): JsonResource
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('username', 'like', $term)
                    ->orWhere('full_name', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', (bool) $request->boolean('is_active')))
            ->orderBy('username')
            ->paginate(min((int) $request->query('per_page', 15), 100));

        return JsonResource::collection($users);
    }

    /**
     * Show a single user (self or admin).
     */
    public function show(User $user): JsonResource
    {
        $this->authorize('view', $user);

        return new JsonResource($user);
    }

    /**
     * Create a user (admin only).
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        AuditLogger::record('user.created', $user, null, AuditLogger::sanitize($user->only(
            ['id', 'username', 'email', 'full_name', 'role', 'is_active'],
        )));

        return response()->json([
            'message' => 'User created.',
            'user'    => new JsonResource($user),
        ], 201);
    }

    /**
     * Update a user (admin only). Username/password are immutable here.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $old = $user->only(['email', 'full_name', 'role', 'is_active']);

        $user->update($request->validated());

        AuditLogger::record('user.updated', $user, $old, AuditLogger::sanitize(
            $user->only(['email', 'full_name', 'role', 'is_active']),
        ));

        return response()->json([
            'message' => 'User updated.',
            'user'    => new JsonResource($user->fresh()),
        ]);
    }

    /**
     * Deactivate a user (soft-delete semantics — historical data must keep
     * working). Admins cannot deactivate their own account.
     */
    public function deactivate(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        if (! $user->is_active) {
            return response()->json(['message' => 'User is already inactive.'], 409);
        }

        $user->is_active = false;
        $user->save();
        $user->tokens()->delete(); // revoke all sessions/tokens immediately

        AuditLogger::record('user.deactivated', $user, ['is_active' => true], ['is_active' => false]);

        return response()->json(['message' => 'User deactivated.']);
    }

    /**
     * Reactivate a previously deactivated user (admin only).
     */
    public function activate(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        if ($user->is_active) {
            return response()->json(['message' => 'User is already active.'], 409);
        }

        $user->is_active = true;
        $user->save();

        AuditLogger::record('user.activated', $user, ['is_active' => false], ['is_active' => true]);

        return response()->json(['message' => 'User activated.']);
    }

    /**
     * Reset a user's password (self or admin).
     */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->authorize('resetPassword', $user);

        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user->password = Hash::make($data['password']);
        // Admin reset → force the user to set their own password at next login.
        $user->password_changed_at = null;
        $user->save();
        $user->tokens()->delete(); // force re-login everywhere

        AuditLogger::record('user.password_reset', $user);

        return response()->json(['message' => 'Password updated.']);
    }
}