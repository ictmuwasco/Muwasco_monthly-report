<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReportingPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\ReportingPeriod::class);
    }

    public function rules(): array
    {
        return [
            // One of month_year (YYYY-MM-DD first-of-month) OR explicit dates.
            'month_year' => ['required', 'date_format:Y-m-d'],
            'name'       => ['sometimes', 'string', 'max:255'],
            'start_date' => ['sometimes', 'date'],
            'end_date'   => ['sometimes', 'date', 'after_or_equal:start_date'],
            'submission_deadline' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'month_year.date_format' => 'month_year must be the first day of the month, e.g. 2025-09-01.',
            'end_date.after_or_equal' => 'End date must be on or after the start date.',
        ];
    }
}
