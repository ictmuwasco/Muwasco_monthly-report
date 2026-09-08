<?php

namespace App\Providers;

use App\Models\Parameter;
use App\Models\ParameterCategory;
use App\Models\ReportingPeriod;
use App\Models\User;
use App\Policies\ParameterPolicy;
use App\Policies\ParameterCategoryPolicy;
use App\Policies\ReportingPeriodPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\ServiceProvider;

/**
 * Registers model-policy mappings for the application.
 *
 * Policies are the authorization security boundary. Every sensitive endpoint
 * must call a policy method (or a gate wrapping one); frontend route hiding is
 * a UX convenience only and never counts as authorization.
 */
class AuthServiceProvider extends ServiceProvider
{
    /**
     * The mapping of model => policy. Keyed by FQCN to match Eloquent's
     * `authorize()` / `Gate::allows()` resolution.
     */
    protected $policies = [
        User::class              => UserPolicy::class,
        Parameter::class         => ParameterPolicy::class,
        ParameterCategory::class => ParameterCategoryPolicy::class,
        ReportingPeriod::class   => ReportingPeriodPolicy::class,
    ];

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            \Gate::policy($model, $policy);
        }
    }
}