<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Public, token-based approval endpoints (the emailed-manager flow).
 *
 * No login required: the manager clicks the secure link from the review email.
 * Rate-limited (10/min) since these are unauthenticated decision endpoints.
 */
class ApprovalTokenController extends Controller
{
    public function __construct(private readonly ApprovalService $service)
    {
    }

    /**
     * Preview the approval request from a token link.
     * GET /approval-requests/{token}
     */
    public function show(string $token): JsonResponse
    {
        $approval = $this->service->resolveByToken($token);

        return response()->json([
            'period'       => $approval->month->only(['id', 'name', 'month_year', 'status']),
            'manager_role' => $approval->manager_role,
            'status'       => $approval->status,
            'expires_at'   => $approval->token_expires_at?->format('Y-m-d H:i'),
            'rejection_reason' => $approval->rejection_reason,
        ]);
    }

    /**
     * Decide via token link.
     * POST /approval-requests/{token}/decide
     * Body: { action: approve|reject, comment? }
     */
    public function decide(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'action'  => ['required', Rule::in(['approve', 'reject'])],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $approval = $this->service->resolveByToken($token);

        $result = $this->service->decide(
            $approval,
            $validated['action'],
            $validated['comment'] ?? null,
            null, // token holder is not a system user
        );

        return response()->json([
            'message' => $validated['action'] === 'approve'
                ? 'Thank you — your approval has been recorded.'
                : 'Thank you — your change request has been recorded.',
            'period'  => $result['period']->only(['id', 'name', 'status']),
        ]);
    }
}
