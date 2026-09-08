# MODELS.md — Eloquent Models (M3)

> **Status:** M3 · Eloquent models mapped 1:1 to the live `maggie_monthlyreport` schema.
> These reflect the **authoritative live DB** (user-based assignment model + `month_approvals`).

All models live in `backend-laravel/app/Models/` (namespace `App\Models`) and map to the
existing tables via explicit `$table`. No table was created/renamed by M3.

## Model ↔ table mapping

| Model | Table | Timestamps | Notes |
|---|---|---|---|
| `User` | `users` | yes | auth identity; `role` enum('admin','user'); `full_name`; `is_active`; no remember_token/email_verified_at |
| `Role` | `roles` | yes | reference catalog; NOT FK-linked to users in live |
| `Parameter` | `parameters` | no | metric; `category_id` implicit (no FK in live); `data_type` enum |
| `ParameterCategory` | `parameter_categories` | no | groups of parameters (sections) |
| `UserParameterAssignment` | `user_parameter_assignments` | no | Pivot; grants user→parameter; UNIQUE(user_id,parameter_id) |
| `UserSectionAssignment` | `user_section_assignments` | no | Pivot; grants user→category; UNIQUE(user_id,category_id) |
| `ReportingPeriod` | `months` | yes | monthly window; `month_year` unique; `status` enum(draft,submitted) |
| `MonthlyData` | `monthly_data` | no | value per (month_id,parameter_id); UNIQUE(month_id,parameter_id) |
| `MonthApproval` | `month_approvals` | yes | added M2; UNIQUE(month_id,manager_role); approval workflow |

## Key relationships

- `User::accessibleParameters()` / `accessibleCategories()` — BelongsToMany through the
  two pivot tables (live user-based assignment model).
- `Parameter::category()` — BelongsTo `ParameterCategory` (implicit, no DB FK).
- `Parameter::assignedUsers()`, `ParameterCategory::assignedUsers()` — inverse pivots.
- `ReportingPeriod::monthlyData()`, `ReportingPeriod::approvals()` (MonthApproval).
- `MonthlyData::month()` / `parameter()` — BelongsTo.
- `MonthApproval::month()`, `notifiedBy()`, `approvedBy()` — BelongsTo.

## Casts / safety

- `User.password` is in `$hidden` (never serialized); `is_active` cast to boolean.
- `User::$guarded = []` plus route/request validation in later modules; mass-assignment
  protection will be reinforced with `$fillable` in the API modules (M5/M6) as needed.
- Foreign-key integer columns cast to `integer`; date columns to `date`/`datetime`.

## Factories

`database/factories/UserFactory.php` reworked to the live `users` schema
(`username`, `full_name`, `role`, `is_active`, `email`, `password`) with `admin()` and
`inactive()` states. Used by M9+ tests/seeders.

## Verification (M3)

`php scripts/m3_verify.php` proves models read the live DB and resolve relationships:
counts (users=10, roles=11, params=162, cats=13, periods=14, data=2206, approvals=0),
`admin`/`revenue_officer` roles resolved, `Parameter→category`,
`MonthlyData→month/parameter`, and `ReportingPeriod→monthlyData` all work.