<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ReportingPeriod;
use App\Services\AuditLogger;
use App\Services\MonthlyDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Monthly data-entry API (M10).
 *
 * Thin controller — all business logic lives in MonthlyDataService:
 * access scoping, typed validation, transactional upserts, submit rules.
 */
class MonthlyDataController extends Controller
{
    public function __construct(private readonly MonthlyDataService $service)
    {
    }

    /**
     * Data-entry form: accessible categories/parameters + saved values.
     * GET /reporting-periods/{period}/data-entry
     */
    public function index(Request $request, ReportingPeriod $period): JsonResponse
    {
        $this->authorize('view', $period);

        return response()->json($this->service->entryForm($request->user(), $period));
    }

    /**
     * Bulk save (draft) values.
     * PUT /reporting-periods/{period}/monthly-data
     * Body: { rows: [{parameter_id, value}, ...] }
     */
    public function save(Request $request, ReportingPeriod $period): JsonResponse
    {
        $this->authorize('view', $period);

        $validated = $request->validate([
            'rows'                 => ['present', 'array'],
            'rows.*.parameter_id'  => ['required', 'integer'],
            'rows.*.value'         => ['nullable'],
        ]);

        $result = $this->service->save($request->user(), $period, $validated['rows']);

        AuditLogger::record('monthly_data.saved', $period, null, [
            'count' => $result['saved'],
            'by'    => $request->user()->username,
        ]);

        return response()->json([
            'message' => "Saved {$result['saved']} value(s).",
            'saved'   => $result['saved'],
        ]);
    }

    /**
     * Submit the period's data (validates required values, sets status=submitted).
     * POST /reporting-periods/{period}/submit
     */
    public function submit(Request $request, ReportingPeriod $period): JsonResponse
    {
        $this->authorize('view', $period);

        $result = $this->service->submit($request->user(), $period);

        return response()->json([
            'message'  => 'Reporting data submitted.',
            'previous' => $result['previous'],
            'period'   => $result['period'],
        ]);
    }
}
