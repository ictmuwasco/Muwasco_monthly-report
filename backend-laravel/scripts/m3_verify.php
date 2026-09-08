<?php
// M3 verification: Eloquent models read real live data + relationships work.
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{
    User, Role, Parameter, ParameterCategory,
    UserParameterAssignment, UserSectionAssignment,
    ReportingPeriod, MonthlyData, MonthApproval
};

echo "counts: users=" . User::count()
    . " roles=" . Role::count()
    . " params=" . Parameter::count()
    . " cats=" . ParameterCategory::count()
    . " periods=" . ReportingPeriod::count()
    . " data=" . MonthlyData::count()
    . " approvals=" . MonthApproval::count()
    . " param_pivots=" . UserParameterAssignment::count()
    . " section_pivots=" . UserSectionAssignment::count() . "\n";

$u = User::with('accessibleParameters')->first();
echo "user[0]={$u->username} role={$u->role} is_admin=" . var_export($u->isAdmin(), true)
    . " accessible_parameters=" . $u->accessibleParameters->count() . "\n";

$p = Parameter::with('category')->first();
echo "param id={$p->id} code={$p->code} category=" . ($p->category?->name ?? 'none') . "\n";

$m = MonthlyData::with(['month', 'parameter'])->first();
echo "monthly_data id={$m->id} month={$m->month?->month_year} param={$m->parameter?->code}\n";

$period = ReportingPeriod::with('approvals')->whereHas('monthlyData')->first();
echo "period id={$period->id} name={$period->name} status={$period->status}"
    . " monthly_data_rows=" . $period->monthlyData()->count() . "\n";

$admin = User::where('role', 'admin')->first();
echo "first admin: " . ($admin ? $admin->username : 'none') . "\n";
$nonAdmin = User::where('role', 'user')->first();
echo "first role=user: " . ($nonAdmin ? $nonAdmin->username : 'none') . "\n";