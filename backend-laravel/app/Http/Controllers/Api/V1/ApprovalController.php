<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MonthApproval;
use App\Models\ReportingPeriod;
use App\Services\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Approval workflow endpoints (authenticated, admin).
 * The emailed-manager token flow lives in ApprovalTokenController (public).
 */
class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalService $service)
    {
    }

    /**
     * List a period's approval requests + decision trail.
     * GET /reporting-periods/{period}/approvals
     */
    public function index(Request $request, ReportingPeriod $period): JsonResponse
    {
        $this->authorize('view', $period);

        return response()->json([
            'period'   => $period->only(['id', 'name', 'status']),
            'approvals' => $period->approvals()->orderBy('id')->get()
                ->makeHidden('approval_token'),
            'histories' => $period->approvals()->with('histories')->get()
                ->flatMap(fn ($a) => $a->histories)->sortBy('id')->values(),
        ]);
    }

    /**
     * Request a manager review of a submitted period.
     * POST /reporting-periods/{period}/approvals
     * Body: { manager_role, notify_email? }
     */
    public function store(Request $request, ReportingPeriod $period): JsonResponse
    {
        $this->authorize('transition', $period);

        $validated = $request->validate([
            'manager_role' => ['required', 'string', Rule::in(ApprovalService::MANAGER_ROLES)],
            'notify_email' => ['nullable', 'email'],
        ]);

        $result = $this->service->requestReview(
            $request->user(),
            $period,
            $validated['manager_role'],
            $validated['notify_email'] ?? null,
        );

        return response()->json([
            'message'    => "Review requested from {$validated['manager_role']}.",
            'approval'   => $result['approval']->makeHidden('approval_token'),
            'emailed_to' => $result['emailed_to'],
            'period'     => $period->fresh(),
        ], 201);
    }

    /**
     * Admin records a manager's decision (approve/reject).
     * POST /reporting-periods/{period}/approvals/{approval}/decide
     * Body: { action: approve|reject, comment? }
     */
    public function decide(Request $request, ReportingPeriod $period, MonthApproval $approval): JsonResponse
    {
        $this->authorize('transition', $period);

        // Guard against cross-period IDOR: the approval must belong to the period.
        if ($approval->month_id !== $period->id) {
            abort(404);
        }

        $validated = $request->validate([
            'action'  => ['required', 'in:approve,reject'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $this->service->decide(
            $approval,
            $validated['action'],
            $validated['comment'] ?? null,
            $request->user(),
        );

        return response()->json([
            'message'  => $validated['action'] === 'approve'
                ? 'Review approved.' : 'Changes requested.',
            'approval' => $result['approval']->makeHidden('approval_token'),
            'period'   => $result['period'],
        ]);
    }
}
