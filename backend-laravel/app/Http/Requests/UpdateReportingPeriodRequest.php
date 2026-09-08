<?php

namespace App\Http\Requests;

use App\Models\ReportingPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReportingPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('period'));
    }

    public function rules(): array
    {
        /** @var ReportingPeriod $period */
        $period = $this->route('period');

        return [
            // month_year identifies the period and is immutable (audit stability).
            'name'       => ['sometimes', 'string', 'max:255'],
            'start_date' => ['sometimes', 'date'],
            'end_date'   => ['sometimes', 'date', 'after_or_equal:start_date'],
            'submission_deadline' => ['nullable', 'date'],
            'status'     => ['prohibited'], // use the transition endpoint instead
        ];
    }

    public function messages(): array
    {
        return [
            'status.prohibited' => 'Status changes must use the /status transition endpoint.',
        ];
    }
}
