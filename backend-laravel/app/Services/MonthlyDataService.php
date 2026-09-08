<?php

namespace App\Services;

use App\Models\MonthlyData;
use App\Models\Parameter;
use App\Models\ReportingPeriod;
use App\Models\User;
use App\Mail\ReportSubmitted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * MonthlyDataService — the core monthly data-entry business logic.
 *
 * Access model (live schema):
 *  - Admin: every parameter.
 *  - User: parameters assigned directly (user_parameter_assignments) PLUS all
 *    parameters inside assigned sections/categories (user_section_assignments).
 *
 * All writes are transactional upserts relying on the DB-level
 * UNIQUE(month_id, parameter_id) constraint as the race-condition backstop.
 */
class MonthlyDataService
{
    /* ── Access scope ────────────────────────────────────── */

    /**
     * Parameter IDs the user may enter data for.
     *
     * @return array<int, int>
     */
    public function accessibleParameterIds(User $user): array
    {
        if ($user->isAdmin()) {
            return Parameter::query()->pluck('id')->all();
        }

        $direct = $user->accessibleParameters()->pluck('parameters.id')->all();
        $bySection = Parameter::query()
            ->whereIn('category_id',
                $user->accessibleCategories()->pluck('parameter_categories.id')->all())
            ->pluck('id')
            ->all();

        return array_values(array_unique(array_merge($direct, $bySection)));
    }

    /* ── Draft save (bulk upsert) ────────────────────────── */

    /**
     * Save a batch of values. Rows: [{parameter_id, value}].
     *
     * @return array{saved: int}
     */
    public function save(User $user, ReportingPeriod $period, array $rows): array
    {
        $this->assertSaveable($period);

        $accessible = array_flip($this->accessibleParameterIds($user));
        $requested  = array_map('intval', array_column($rows, 'parameter_id'));
        $params     = Parameter::whereIn('id', $requested)->get()->keyBy('id');

        $errors = [];
        $clean  = [];

        foreach ($rows as $i => $row) {
            $pid = (int) ($row['parameter_id'] ?? 0);

            if (! isset($accessible[$pid])) {
                $errors["rows.{$i}.parameter_id"] = "You are not assigned to parameter {$pid}.";
                continue;
            }

            $param = $params->get($pid);
            if (! $param) {
                $errors["rows.{$i}.parameter_id"] = "Parameter {$pid} does not exist.";
                continue;
            }

            $typeError = $this->typeError($param, $row['value'] ?? null);
            if ($typeError !== null) {
                $errors["rows.{$i}.value"] = $typeError;
                continue;
            }

            $clean[] = ['parameter_id' => $pid, 'value' => $this->canonical($row['value'] ?? null)];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($period, $clean) {
            foreach ($clean as $row) {
                MonthlyData::updateOrCreate(
                    ['month_id' => $period->id, 'parameter_id' => $row['parameter_id']],
                    ['value'    => $row['value']]
                );
            }
        });

        return ['saved' => count($clean)];
    }

    /* ── Submit ──────────────────────────────────────────── */

    /**
     * Submit the period's data: validates all required (accessible) parameters
     * have values, then moves the period into 'submitted'.
     */
    public function submit(User $user, ReportingPeriod $period): array
    {
        $this->assertSaveable($period);

        if (! in_array($period->status, [ReportingPeriod::STATUS_DRAFT,
            ReportingPeriod::STATUS_OPEN, ReportingPeriod::STATUS_CHANGES_REQUESTED], true)) {
            throw ValidationException::withMessages([
                'period' => "Period in status [{$period->status}] cannot be submitted.",
            ]);
        }

        $ids = $this->accessibleParameterIds($user);

        // Required (accessible) parameters must have non-empty saved values.
        $missing = Parameter::query()
            ->whereIn('id', $ids)
            ->where('required', true)
            ->whereNotExists(function ($q) use ($period) {
                $q->select(DB::raw(1))
                    ->from('monthly_data as md')
                    ->whereColumn('md.parameter_id', 'parameters.id')
                    ->where('md.month_id', $period->id)
                    ->whereNotNull('md.value')
                    ->where('md.value', '!=', '');
            })
            ->pluck('code')
            ->all();

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'required' => 'Missing required values for parameters: '.implode(', ', $missing).'.',
            ]);
        }

        $previous = $period->status;

        // Draft periods pass through 'open' on the way to 'submitted'.
        if ($period->status === ReportingPeriod::STATUS_DRAFT) {
            $period->transitionTo(ReportingPeriod::STATUS_OPEN);
        }

        $period->transitionTo(ReportingPeriod::STATUS_SUBMITTED);

        AuditLogger::record('reporting_period.submitted', $period,
            ['status' => $previous], ['status' => $period->status, 'by' => $user->username]);

        // Notify active admins (queued) that the period is awaiting review.
        $admins = User::query()->where('role', 'admin')->where('is_active', true)->get();
        foreach ($admins as $admin) {
            Mail::to($admin->email)->send(new ReportSubmitted($period, $user));
        }

        return ['previous' => $previous, 'period' => $period->fresh()];
    }

    /* ── Data-entry form payload ─────────────────────────── */

    /**
     * Categories (with parameters + saved values) the user can enter for a period.
     */
    public function entryForm(User $user, ReportingPeriod $period): array
    {
        $ids   = $this->accessibleParameterIds($user);
        $saved = MonthlyData::where('month_id', $period->id)
            ->whereIn('parameter_id', $ids)
            ->pluck('value', 'parameter_id');

        $parameters = Parameter::query()
            ->with('category:id,name,display_order')
            ->whereIn('id', $ids)
            ->get()
            ->each(function (Parameter $p) use ($saved) {
                $p->saved_value = $saved[$p->id] ?? null;
            });

        $categories = $parameters
            ->groupBy(fn (Parameter $p) => $p->category_id ?? 0)
            ->map(function (Collection $group) {
                $cat = $group->first()->category;

                return [
                    'id'            => $cat?->id,
                    'name'          => $cat?->name ?? 'Uncategorised',
                    'display_order' => $cat?->display_order ?? 0,
                    'parameters'    => $group->sortBy('code')->values()->map(
                        fn (Parameter $p) => $this->parameterPayload($p)
                    )->all(),
                ];
            })
            ->sortBy('display_order')
            ->values();

        $savable = $this->canSave($period)->passes;

        return [
            'period'           => $period->only(['id', 'name', 'month_year', 'start_date',
                                    'end_date', 'submission_deadline', 'status']),
            'is_locked'        => $period->isLockedForEditing(),
            'is_past_deadline' => $period->isPastDeadline(),
            'can_save'         => $savable,
            'can_submit'       => $savable && $this->canSubmit($period)->passes,
            'categories'       => $categories->all(),
        ];
    }

    /* ── Internals ───────────────────────────────────────── */

    private function parameterPayload(Parameter $p): array
    {
        return [
            'id'          => $p->id,
            'code'        => $p->code,
            'label'       => $p->label,
            'data_type'   => $p->data_type,
            'unit'        => $p->unit,
            'required'    => (bool) $p->required,
            'saved_value' => $p->saved_value,
        ];
    }

    /**
     * Validate a raw value against the parameter's data_type.
     * Returns an error message, or null when valid (null/'' allowed = draft).
     */
    private function typeError(Parameter $param, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null; // empty is fine while drafting; enforced on submit
        }

        return match ($param->data_type) {
            'number'     => is_numeric($value) ? null : 'Must be a number.',
            'currency'   => is_numeric($value) ? null : 'Must be a numeric amount.',
            'percentage' => ! is_numeric($value) ? 'Must be a number.'
                : ($value < 0 || $value > 100 ? 'Must be between 0 and 100.' : null),
            'text'       => is_string($value) && mb_strlen($value) <= 1000
                ? null : 'Must be text of at most 1000 characters.',
            default      => 'Unknown data type.',
        };
    }

    /** Canonical string representation for the text value column. */
    private function canonical(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (string) (0 + $value) : (string) $value;
    }

    private function canSave(ReportingPeriod $period): object
    {
        if ($period->isLockedForEditing()) {
            return (object) ['passes' => false, 'reason' => 'Period is locked.'];
        }

        if ($period->isPastDeadline()) {
            return (object) ['passes' => false, 'reason' => 'Submission deadline has passed.'];
        }

        return (object) ['passes' => true, 'reason' => null];
    }

    private function canSubmit(ReportingPeriod $period): object
    {
        $ok = in_array($period->status, [ReportingPeriod::STATUS_DRAFT,
            ReportingPeriod::STATUS_OPEN, ReportingPeriod::STATUS_CHANGES_REQUESTED], true);

        return (object) ['passes' => $ok,
            'reason' => $ok ? null : 'Period cannot be submitted from its current status.'];
    }

    private function assertSaveable(ReportingPeriod $period): void
    {
        $check = $this->canSave($period);

        if (! $check->passes) {
            throw ValidationException::withMessages(['period' => $check->reason]);
        }
    }
}
