<?php

namespace App\Services;

use App\Models\MonthlyData;
use App\Models\ParameterCategory;
use App\Models\ReportingPeriod;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * ReportService — builds the monthly monitoring report dataset (M13).
 *
 * Preserves the legacy business format:
 *  - at least N months must be selected (config('reports.min_months'), legacy: 3);
 *  - months are ordered chronologically (earliest first);
 *  - categories ordered by display_order, parameters by code;
 *  - missing values render as '-'.
 */
class ReportService
{
    /**
     * Load the selected reporting periods, validated and chronologically sorted.
     *
     * @param  array<int, int|string>  $monthIds
     * @return Collection<int, ReportingPeriod>
     */
    public function loadPeriods(array $monthIds): Collection
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($monthIds, 'strlen'))));

        if (count($ids) < config('reports.min_months', 3)) {
            throw ValidationException::withMessages([
                'months' => 'Select at least '.config('reports.min_months', 3).' months to generate a report.',
            ]);
        }

        $periods = ReportingPeriod::whereIn('id', $ids)
            ->orderBy('start_date')
            ->get();

        if ($periods->count() !== count($ids)) {
            $found = $periods->pluck('id')->all();
            $missing = array_diff($ids, $found);

            throw ValidationException::withMessages([
                'months' => 'Unknown reporting period(s): '.implode(', ', $missing).'.',
            ]);
        }

        return $periods;
    }

    /**
     * Report dataset: categories → parameter rows → one value per period.
     */
    public function buildDataset(Collection $periods): array
    {
        $data = MonthlyData::query()
            ->whereIn('month_id', $periods->pluck('id'))
            ->get()
            ->groupBy('parameter_id');

        $valuesFor = function (int $parameterId) use ($periods, $data) {
            $rows = $data->get($parameterId, collect())->keyBy('month_id');

            return $periods->map(
                fn (ReportingPeriod $p) => $rows[$p->id]->value ?? null
            )->all();
        };

        $categories = ParameterCategory::query()
            ->with(['parameters' => fn ($q) => $q->orderBy('code')])
            ->orderBy('display_order')
            ->get();

        $dataset = [];

        foreach ($categories as $category) {
            $rows = [];

            foreach ($category->parameters as $parameter) {
                $rows[] = [
                    'code'   => $parameter->code,
                    'label'  => $parameter->label,
                    'unit'   => $parameter->unit,
                    'values' => $valuesFor($parameter->id),
                ];
            }

            if ($rows === []) {
                continue; // legacy: skip empty categories entirely
            }

            $dataset[] = [
                'name'       => $category->name,
                'parameters' => $rows,
            ];
        }

        return $dataset;
    }

    /**
     * Everything the PDF/preview views need.
     */
    public function buildReport(array $monthIds): array
    {
        $periods = $this->loadPeriods($monthIds);

        return [
            'periods'     => $periods,
            'dataset'     => $this->buildDataset($periods),
            'generated_at' => now()->format('d F Y, H:i'),
        ];
    }
}
