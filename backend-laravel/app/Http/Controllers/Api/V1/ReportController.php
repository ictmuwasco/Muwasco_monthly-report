<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reports endpoint (M13) — monthly monitoring report in JSON preview or PDF.
 *
 * Preserves the legacy business rules (≥3 months, chronological order,
 * categories by display_order, '-' for missing values). Signatories are now
 * driven from config/reports.php instead of being hard-coded in the generator.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $service)
    {
    }

    /**
     * JSON preview of the report dataset.
     * GET /reports/preview?months[]=1&months[]=2&...
     */
    public function preview(Request $request): JsonResponse
    {
        $months = $request->query('months', []);

        $report = $this->service->buildReport(is_array($months) ? $months : [$months]);

        return response()->json([
            'periods'      => $report['periods']->map(
                fn ($p) => $p->only(['id', 'name', 'month_year', 'status'])
            ),
            'categories'   => $report['dataset'],
            'generated_at' => $report['generated_at'],
        ]);
    }

    /**
     * PDF download of the report.
     * GET /reports/pdf?months[]=1&months[]=2&...
     */
    public function pdf(Request $request)
    {
        $months = $request->query('months', []);

        $report = $this->service->buildReport(is_array($months) ? $months : [$months]);

        $pdf = Pdf::loadView('reports.monitoring', [
            'categories'   => $report['dataset'],
            'periods'      => $report['periods'],
            'generated_at' => $report['generated_at'],
        ])->setPaper('a4');

        return $pdf->download('muwasco_monitoring_report.pdf');
    }
}