# Parameters & Categories API (M8)

> **Status:** ✅ done · 51 tests (116 assertions) · live DB intact

## What it does
Read + admin-managed write APIs for the reference data that drives monthly reporting:
**parameters** (the metrics being reported) and **parameter_categories** (their groups/sections).

The live `parameters` table has columns `id, category_id, code, label, data_type, unit, required`
(no `name`, no timestamps). The live `parameter_categories` table has `id, name, description, display_order`
(no timestamps). Both controllers disable automatic timestamp maintenance on write.

## Endpoints (`auth:sanctum`)

### Parameters (`/api/v1/parameters`)
| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/parameters` | any auth | paginated, `?category_id=` filter, `?per_page=`, eager-loads `category`, ordered by `code` |
| GET | `/parameters/{parameter}` | any auth | eager-loads `category` |
| POST | `/parameters` | **admin** | validated create (`code` unique, `data_type` enum, `category_id` exists) |
| PATCH | `/parameters/{parameter}` | **admin** | validated update (immutable `code`) |

### Categories (`/api/v1/parameter-categories`)
| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/parameter-categories` | any auth | paginated, `withCount('parameters')`, ordered by `display_order` |
| GET | `/parameter-categories/{category}` | any auth | eager-loads `parameters` |
| POST | `/parameter-categories` | **admin** | validated create (`name` unique) |
| PATCH | `/parameter-categories/{category}` | **admin** | validated update (immutable `name`) |

## Authorization
- `ParameterPolicy` / `ParameterCategoryPolicy` — `viewAny`/`view` = any authenticated; `create`/`update`/`delete` = admin only.
- Registered in `AuthServiceProvider` via `Gate::policy()`.

## Validation
- `StoreParameterRequest` / `UpdateParameterRequest` — `code` unique, `data_type` in (numeric,text,percentage,currency,boolean), `category_id` exists, `required` boolean.
- `StoreParameterCategoryRequest` / `UpdateParameterCategoryRequest` — `name` unique, `display_order` int.

## Audit
Every write records an `audit_logs` row (`parameter.created/updated`, `parameter_category.created/updated`) with actor, entity, old/new values (sanitized).

## Bugs found & fixed
1. **`parameters.name` does not exist** — the live column is `code`/`label`. `AssignmentController::index()` ordered by `parameters.name` → 500. Fixed to `parameters.code`. Verified no other `parameters.name` references remain.
2. Both `Parameter` and `ParameterCategory` lack timestamp columns → writes set `->timestamps = false` to avoid SQL errors.

## Verification
- 51 tests pass (16 new for M8), including policy 403s, unique-code/name rejection, immutable-field rejection, category filter, pagination, and audit contents.
- Live DB intact: users=10, roles=11, parameters=162, parameter_categories=13, months=14, monthly_data=2206.
- All 9 parameter/category routes registered.
