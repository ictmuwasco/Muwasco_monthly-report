<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MonthlyData — maps to the live `monthly_data` table.
 *
 * Captured value per (month_id, parameter_id). The UNIQUE(month_id, parameter_id)
 * key prevents duplicates; upserts rely on it.
 */
class MonthlyData extends Model
{
    protected $table = 'monthly_data';

    public $timestamps = false;

    /** @var array<int, string> */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'month_id'    => 'integer',
            'parameter_id'=> 'integer',
            'created_at'  => 'datetime',
        ];
    }

    public function month(): BelongsTo
    {
        return $this->belongsTo(ReportingPeriod::class, 'month_id');
    }

    public function parameter(): BelongsTo
    {
        return $this->belongsTo(Parameter::class, 'parameter_id');
    }
}
