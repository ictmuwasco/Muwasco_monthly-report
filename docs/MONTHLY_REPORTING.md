# Monthly Data Entry API (M10)

> **Status:** ✅ done · 89 tests total (227 assertions) · live DB intact

## What it does
The core data-entry module: users save monthly values for their **assigned parameters**
within a reporting period, then **submit** the period for review. All business rules are
enforced server-side in `App\Services\MonthlyDataService`; the controller is thin.

## Access model (live schema, per-user assignments)
- **Admin** → every parameter.
- **User** → parameters assigned directly (`user_parameter_assignments`) **plus** all
  parameters in assigned sections (`user_section_assignments` → `parameters.category_id`).
- Saving a value for a non-accessible parameter is rejected (IDOR protection), even if
  the parameter id is guessed/forced from the client.

## Endpoints (`auth:sanctum`)
| Method | Path | Notes |
|---|---|---|
| GET | `/reporting-periods/{period}/data-entry` | The entry form: accessible categories (ordered by `display_order`) with parameters (ordered by `code`), each carrying `data_type`, `unit`, `required`, and `saved_value`; plus `can_save` / `can_submit` / `is_locked` / `is_past_deadline` flags |
| PUT | `/reporting-periods/{period}/monthly-data` | Bulk draft save. Body `{ rows: [{parameter_id, value}] }`. Transactional `updateOrCreate` upsert on `(month_id, parameter_id)` — the DB UNIQUE constraint is the race-condition backstop. Re-saving overwrites; no duplicate rows |
| POST | `/reporting-periods/{period}/submit` | Validates all **required accessible** parameters have non-empty values, then transitions the period → `submitted` (draft passes through `open`; also allowed from `changes_requested`) |

## Validation rules (typed per `parameter.data_type`)
| data_type | Rule |
|---|---|
| `number` | must be numeric |
| `currency` | must be numeric |
| `percentage` | numeric and 0–100 |
| `text` | string, ≤1000 chars |
| empty/null | always allowed while drafting (stored as NULL) — enforced at submit |

Values are canonicalised on write (`"007"` → `"7"`; text preserved as-is) into the live
`monthly_data.value` TEXT column.

## Period guards (server-enforced)
- Save/submit blocked when the period is **locked** (`closed`, `under_review`, `approved`) → 422.
- Save/submit blocked when **past `submission_deadline`** → 422.
- Submit blocked from `submitted`/`under_review`/`approved`/`rejected`/`closed` → 422.
- Submit scope: only the **submitter's accessible** required parameters block submission —
  a section officer is never blocked by another section's required parameters.

## Audit
- `monthly_data.saved` (per save batch: count + actor)
- `reporting_period.submitted` (previous status, actor)

## Verification
- **17 new tests**; full suite **89 passed (227 assertions)**.
- Coverage: admin sees all; user sees only assigned (direct + section merge, sorted);
  no-assignment user sees nothing; unauthenticated 401; bulk save; upsert-overwrite with
  no duplicates; unassigned-parameter rejection; number/percentage type rejections;
  locked-period and past-deadline blocks (save + submit); required-values gating;
  draft→open→submitted walk; submitted-period block; scope-limited required check; audit log.
- Test destructure + duplicate-user helper bugs found and fixed during development.
- Live DB intact: users=10, months=14, monthly_data=2206, user_parameter_assignments=164.
