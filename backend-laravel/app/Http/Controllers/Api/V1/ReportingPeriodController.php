<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReportingPeriodRequest;
use App\Http\Requests\UpdateReportingPeriodRequest;
use App\Models\ReportingPeriod;
use App\Services\AuditLogger;
use InvalidArgumentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reporting periods API (the legacy `months` table).
 *
 * Lifecycle: draft → open → submitted → under_review → approved/rejected → closed.
 * Status changes go through the dedicated /status endpoint which enforces the
 * server-side transition map; closed periods are never hard-deleted.
 */
class ReportingPeriodController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ReportingPeriod::class);

        $query = ReportingPeriod::query()
            ->withCount('monthlyData')
            ->orderByRecent();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($year = $request->query('year')) {
            $query->whereYear('start_date', (int) $year);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('month_year', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $query->paginate($request->query('per_page', 25))
        );
    }

    public function show(ReportingPeriod $period): JsonResponse
    {
        $this->authorize('view', $period);

        $period->loadCount(['monthlyData', 'approvals']);

        return response()->json([
            'period'            => $period,
            'can_transition_to' => ReportingPeriod::allowedTransitions()[$period->status] ?? [],
            'is_locked'         => $period->isLockedForEditing(),
            'is_past_deadline'  => $period->isPastDeadline(),
        ]);
    }

    public function store(StoreReportingPeriodRequest $request): JsonResponse
    {
        $data = $this->resolveDates($request->validated());

        // Duplicate guard — month_year is UNIQUE in the DB; surface it as 422.
        if (ReportingPeriod::where('month_year', $data['month_year'])->exists()) {
            throw ValidationException::withMessages([
                'month_year' => "A reporting period for {$data['month_year']} already exists.",
            ]);
        }

        $data['status']     = $data['status'] ?? ReportingPeriod::STATUS_DRAFT;
        $data['created_by'] = $request->user()->username;

        $period = ReportingPeriod::create($data);

        AuditLogger::record('reporting_period.created', $period, null,
            AuditLogger::sanitize($period->getAttributes()));

        return response()->json($period->fresh(), 201);
    }

    public function update(UpdateReportingPeriodRequest $request, ReportingPeriod $period): JsonResponse
    {
        $oldValues = AuditLogger::sanitize($period->getAttributes());

        $period->fill($request->validated());
        $period->save();

        AuditLogger::record('reporting_period.updated', $period, $oldValues,
            AuditLogger::sanitize($period->fresh()->getAttributes()));

        return response()->json($period->fresh());
    }

    /**
     * Admin status transition — enforces the server-side state machine.
     * Body: { status: string, comment?: string }
     */
    public function transition(Request $request, ReportingPeriod $period): JsonResponse
    {
        $this->authorize('transition', $period);

        $validated = $request->validate([
            'status'  => ['required', 'string', Rule::in(ReportingPeriod::statuses())],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $previous = $period->status;

        try {
            $period->transitionTo($validated['status']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'status' => $e->getMessage(),
            ]);
        }

        AuditLogger::record('reporting_period.status_changed', $period,
            ['status' => $previous],
            ['status' => $period->status, 'comment' => $validated['comment'] ?? null]);

        return response()->json([
            'message'  => "Status changed from {$previous} to {$period->status}.",
            'previous' => $previous,
            'period'   => $period->fresh(),
        ]);
    }

    /**
     * Derive name/start_date/end_date from month_year when not provided,
     * matching the legacy convention (e.g. 2025-09-01 → "September 2025").
     */
    private function resolveDates(array $data): array
    {
        $month = \Carbon\Carbon::parse($data['month_year'])->startOfMonth();

        $data['month_year'] ??= $month->toDateString();
        $data['name']       ??= $month->format('F Y');
        $data['start_date'] ??= $month->toDateString();
        $data['end_date']   ??= $month->copy()->endOfMonth()->toDateString();

        return $data;
    }
}
