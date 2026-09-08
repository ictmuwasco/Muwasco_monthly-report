# DATABASE.md — MUWASCO Monthly Report **Live** Schema (Source of Truth)

> **Status:** M1 (revised) + M2
> **Source of truth (per user decision):** the **live MySQL database `maggie_monthlyreport`**.
> The seed file `database/schema/maggie_monthlyreport.sql` is a **historical reference** and
> now differs from live (role-based model + approvals); it is **not** authoritative.
>
> The live database is authoritative and its data must be preserved. Any change is an
> **incremental / non-destructive migration** (additive, backfilled first), never a drop or
> rename of live data.

---

## 1. Overview

The **live** database contains the **source-of-truth business tables** below plus framework
infrastructure tables. It models a water-utility monthly performance reporting workflow
using a **user-based assignment** model:

- **Reporting periods** (`months`) hold calendar months that staff fill in.
- **Parameters** (`parameters`) are the individual metrics, grouped into **categories**
  (`parameter_categories`).
- Users are assigned access to specific **parameters** and **categories** directly
  (`user_parameter_assignments`, `user_section_assignments`) — no role pivots in live.
- Users enter **values** into `monthly_data` keyed by `(month_id, parameter_id)`.
- **`month_approvals`** (added M2) records the technical/commercial manager approval of a
  reporting period — the home for the approval workflow (M11).
- **`audit_logs`** (added M4) append-only application audit trail.
- **`approval_histories`** (added M4) append-only approval transition history.

`users` carries an `enum('admin','user')` `role` column (not an FK). A separate `roles`
table exists with 11 seeded rows but is **not** linked to `users` via FK in the live DB.

> **Framework (non-business) tables** also present: `migrations`, `cache`, `cache_locks`,
> `jobs`, `job_batches`, `failed_jobs` — managed by Laravel, not business data.

---

## 2. Entity-Relationship Summary (ERD)

```
parameter_categories ─┬─ user_section_assignments (user_id, category_id)
        ▲             │        │ (FK users CASCADE)
        │ id          ▼        ▼
        │        parameters    users  ──┬─ user_parameter_assignments
        │  id, code(uniq), label,       │   (user_id, parameter_id)
        │  category_id, data_type      role enum('admin','user')
        │                              email, full_name, is_active
        ▼
   monthly_data (month_id, parameter_id) ──► months (period)
   UNIQUE(month_id, parameter_id)           month_year uniq; status enum(draft,submitted)

   roles (name uniq, description) — reference-only, not FK-linked to users.
```

### Relationship rules (FKs present in live)
| Source | Target | FK name | ON DELETE |
|---|---|---|---|
| `monthly_data.month_id` | `months.id` | `monthly_data_ibfk_1` | CASCADE |
| `monthly_data.parameter_id` | `parameters.id` | `monthly_data_ibfk_2` | CASCADE |
| `user_parameter_assignments.user_id` | `users.id` | `user_parameter_assignments_ibfk_1` | CASCADE |
| `user_parameter_assignments.parameter_id` | `parameters.id` | `user_parameter_assignments_ibfk_2` | CASCADE |
| `user_section_assignments.user_id` | `users.id` | `user_section_assignments_ibfk_1` | CASCADE |
| `user_section_assignments.category_id` | `parameter_categories.id` | `user_section_assignments_ibfk_2` | CASCADE |

---

## 3. Table Reference (live, verified)

### 3.1 `users`
| Column | Type | Null | Key | Notes |
|---|---|---|---|---|
| id | int(11) | NO | PRI (auto) | |
| username | varchar(100) | NO | UNI | login name |
| password | varchar(255) | NO | | bcrypt hash |
| full_name | varchar(255) | NO | | NOT NULL in live |
| email | varchar(255) | YES | | |
| role | enum('admin','user') | YES | | default 'user' (not an FK) |
| is_active | tinyint(1) | YES | | default 1 |
| created_at | timestamp | NO | | default now |

### 3.2 `roles`
Reference table; **not** FK-linked to `users` in live.

| Column | Type | Null | Key | Notes |
|---|---|---|---|---|
| id | int(11) | NO | PRI | |
| name | varchar(100) | NO | UNI | |
| description | text | YES | | |
| created_at | timestamp | NO | | |

11 seeded rows (admin, technical_manager, commercial_manager, *_officer …).

### 3.3 `parameters`
| Column | Type | Null | Key | Notes |
|---|---|---|---|---|
| id | int(11) | NO | PRI (auto) | |
| category_id | int(11) | YES | | → parameter_categories (no FK) |
| code | varchar(10) | NO | UNI | metric code |
| label | text | NO | | human-readable text |
| data_type | enum(number,text,currency,percentage) | YES | | default 'text' |
| unit | varchar(50) | YES | | |
| required | tinyint(1) | YES | | default 0 |

### 3.4 `parameter_categories`
| Column | Type | Null | Key | Notes |
|---|---|---|---|---|
| id | int(11) | NO | PRI (auto) | |
| name | varchar(255) | NO | | |
| description | text | YES | | |
| display_order | int(11) | YES | | default 0 |

13 rows in live.

### 3.5 `user_parameter_assignments`
Grants a **user** access to a **parameter**.

| Column | Type | Null | Key | Notes |
|---|---|---|---|---|
| id | int(11) | NO | PRI (auto) | |
| user_id | int(11) | NO | MUL | FK→users (CASCADE) |
| parameter_id | int(11) | NO | MUL | FK→parameters (CASCADE) |
| assigned_at | timestamp | NO | | default now |

**UNIQUE `unique_user_parameter` (user_id, parameter_id)** — prevents duplicates.

### 3.6 `user_section_assignments`
Grants a **user** access to a **category** (section).

| Column | Type | Null | Key | Notes |
|---|---|---|---|---|
| id | int(11) | NO | PRI (auto) | |
| user_id | int(11) | NO | MUL | FK→users (CASCADE) |
| category_id | int(11) | NO | MUL | FK→parameter_categories (CASCADE) |
| assigned_at | timestamp | NO | | default now |

**UNIQUE `unique_user_category` (user_id, category_id)**. 0 rows in live.

### 3.7 `months` (reporting period)
| Column | Type | Null | Key | Notes |
|---|---|---|---|---|
| id | int(11) | NO | PRI (auto) | |
| name | varchar(255) | YES | | e.g. "June 2025" |
| month_year | varchar(20) | YES | UNI | |
| start_date | date | YES | | |
| end_date | date | YES | | |
| status | enum('draft','submitted') | YES | | default 'draft' |
| created_by | varchar(100) | YES | | default 'System' (name string) |
| created_at | timestamp | NO | | |

### 3.8 `monthly_data`
| Column | Type | Null | Key | Notes |
|---|---|---|---|---|
| id | int(11) | NO | PRI (auto) | |
| month_id | int(11) | YES | MUL | FK→months (CASCADE) |
| parameter_id | int(11) | YES | MUL | FK→parameters (CASCADE) |
| value | text | YES | | |
| created_at | timestamp | NO | | |

**UNIQUE `unique_month_parameter` (month_id, parameter_id)** — the duplicate-prevention
key used by `INSERT … ON DUPLICATE KEY UPDATE` upserts.

### 3.9 `month_approvals` *(added M2 — approval workflow)*
Records the technical/commercial manager approval of a reporting period.

| Column | Type | Null | Key | Notes |
|---|---|---|---|---|
| id | bigint(20) unsigned | NO | PRI (auto) | Laravel-style PK |
| month_id | int(11) | NO | MUL | FK→months.id (CASCADE) |
| manager_role | enum(technical_manager,commercial_manager) | NO | | which approver |
| status | enum(pending,notified,approved,rejected) | NO | | default 'pending' |
| notified_at | datetime | YES | | |
| notified_by | int(11) | YES | MUL | FK→users.id (SET NULL) |
| approved_at | datetime | YES | | |
| approved_by_user_id | int(11) | YES | MUL | FK→users.id (SET NULL) — single approver ref |
| rejection_reason | text | YES | | |
| approval_token | varchar(64) | YES | MUL | signed email-link token |
| token_expires_at | datetime | YES | | |
| created_at / updated_at | timestamp | YES | | |

**UNIQUE `unique_month_manager` (month_id, manager_role)** — one approval row per
(period, manager). Index `idx_approval_token`.
### 3.10 `audit_logs` *(added M4 — append-only audit trail)*
| Column | Type | Null | Key | Notes |
|---|---|---|---|---|
| id | bigint(20) unsigned | NO | PRI | Laravel PK |
| actor_user_id | int(11) | YES | MUL | FK→users.id (SET NULL); the acting user |
| action | varchar(255) | NO | MUL | e.g. 'user.created', 'report_approval.decided' |
| entity_type | varchar(255) | YES | MUL | e.g. 'App\Models\ReportingPeriod' |
| entity_id | int(11) | YES | | |
| old_values | json | YES | | previous state (excludes secrets) |
| new_values | json | YES | | new state (excludes secrets) |
| ip_address | varchar(45) | YES | | |
| user_agent | varchar(255) | YES | | |
| created_at | timestamp | YES | | append-only (no updated_at) |

Appendix-only: no update/delete endpoints. Indexes on (entity_type, entity_id), actor,
action.

### 3.11 `approval_histories` *(added M4 — approval transitions)*
| Column | Type | Null | Key | Notes |
|---|---|---|---|---|
| id | bigint unsigned | NO | PRI | |
| approval_id | bigint unsigned | NO | MUL | FK→month_approvals.id (CASCADE) |
| action | enum(notified,approved,rejected) | NO | MUL | transition type |
| prev_status | varchar(255) | YES | | |
| new_status | varchar(255) | YES | | |
| actor_user_id | int(11) | YES | MUL | FK→users.id (SET NULL) |
| comment | text | YES | | rejection reason etc. |
| created_at | timestamp | YES | | append-only |

Used by the approval workflow (M11) to record every state transition.
---

## 4. Keys, Indexes & Constraints (live)

| Table | Unique keys | Secondary | FK constraints (ON DELETE) |
|---|---|---|---|
| users | username | — | — |
| roles | name | — | — |
| parameters | code | — | category_id→parameter_categories (RESTRICT, added M4) |
| parameter_categories | — | — | — |
| user_parameter_assignments | (user_id, parameter_id) | parameter_id | user→users CASCADE; parameter→parameters CASCADE |
| user_section_assignments | (user_id, category_id) | user_id, category_id | user→users CASCADE; category→parameter_categories CASCADE |
| months | month_year | — | — |
| monthly_data | (month_id, parameter_id) | parameter_id | month→months CASCADE; parameter→parameters CASCADE |
| month_approvals | (month_id, manager_role) | idx_approval_token, approved_by_user_id | month→months CASCADE; notified_by→users SET NULL; approved_by_user_id→users SET NULL |
| audit_logs | — | (entity_type,entity_id), actor_user_id, action | actor_user_id→users SET NULL |
| approval_histories | — | approval_id, action | approval_id→month_approvals CASCADE; actor_user_id→users SET NULL |

Row counts (live): users=10, roles=11, parameters=162, parameter_categories=13,
user_parameter_assignments=164, user_section_assignments=0, months=14, monthly_data=2206,
month_approvals=0, audit_logs=0, approval_histories=0.

---

## 5. Findings / design decisions for later modules

1. **User-based assignments** (not role-based): M7 must use `user_parameter_assignments`
   and `user_section_assignments`. The `roles` table is reference data, not FK-linked.
2. **`users.role` is `enum('admin','user')`**, not `role_id` — authz (M5/M6) checks this
   enum plus `is_active`; do not assume an admin role_id.
3. **Approval workflow table exists.** `month_approvals` (M2) + `approval_histories` (M4)
   — M11 builds the approval workflow on them (state transitions appended to
   `approval_histories`).
4. **`parameters.category_id` FK now added** (M4) → `parameter_categories.id`, ON DELETE
   RESTRICT (verified: no orphans/null).
5. **`months.status` limited to `draft/submitted`** — a richer reporting-period lifecycle
   (open/closed/locked) should be an additive nullable column (M9), not an enum rewrite.
6. **Audit trail exists.** `audit_logs` (M4) — append-only, non-editable-by-users;
   M15 exposes it read-only. Exclude secrets.
7. Mixed charsets possible (MariaDB defaults) — normalize as an isolated, later task.
8. **`user_section_assignments` currently has 0 rows** — verify intended usage before
   building UI on it.

---

## 6. Maintenance
- **Source DB:** live `maggie_monthlyreport` (authoritative).
- **Historical reference:** `database/schema/maggie_monthlyreport.sql` (role-based; differs
  from live — do not treat as authoritative).
- **Backups:** `database/backups/`.
- Update this doc whenever the live schema changes.
Not an FK in live: `parameters.category_id` (implicit only).