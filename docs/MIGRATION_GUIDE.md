# MIGRATION_GUIDE.md

> **Status:** M2 · Laravel scaffold + DB connection verified; **schema discrepancy flagged (blocking for M3/M4).**

This guide records the migration conventions and the authoritative facts this
repo-lifting project must follow. It is updated progressively as each module (M1–M28)
is completed.

---

## 1. Layout convention (decided — do not re-litigate)

The running legacy PHP application currently occupies `backend/` and `frontend/` and must
**not be broken** until each module has fully migrated and been verified.

Because the legacy app and Laravel both need the same `App\` namespace, an `app/`
directory tree, and a `bootstrap/app.php`, they **cannot coexist inside `backend/`**.

**Convention:** the new Laravel application lives in **`backend-laravel/`** (a sibling
directory). Legacy PHP stays untouched in `backend/` for the duration of the migration.

- New API/backend work → `backend-laravel/`
- React work later → `frontend-react/` (decided when we reach Phase D, M16)
- **Cutover:** only after every module migrates, legacy is retired, and data is verified,
  we consolidate — move `backend-laravel/*` into `backend/` and remove the legacy tree.

> Do not run `composer create-project` / Laravel into `backend/` while legacy lives there.

---

## 2. Database source of truth — RESOLVED

**User decision (M2):** the **live MySQL database `maggie_monthlyreport` is authoritative**.
Use its existing 8-table **user-based** schema. The seed file
`database/schema/maggie_monthlyreport.sql` is kept only as a **historical reference**.

### Authoritative live schema (verified during M2)
**8 tables, 0 views:**
`users` (`role` enum admin/user, `full_name`), `roles` (reference only, not FK-linked),
`parameters`, `parameter_categories`, `user_parameter_assignments`,
`user_section_assignments`, `months`, `monthly_data`.

- Assignments are **per-user** (`user_parameter_assignments`, `user_section_assignments`),
  each with a unique `(user_id, target_id)` constraint.
- **No approval workflow table** in live (`month_approvals` absent).
- `monthly_data` keeps `UNIQUE(month_id, parameter_id)` for duplicate prevention.

Full detail: `docs/DATABASE.md`. Eloquent models (M3) and migrations (M4) are written
against this live schema.

---

## 3. Working rules

- **One module at a time** on user command (M1 → … → M28).
- Each module ends with a concrete verification step.
- Each module is independently committable.
- Docs are written progressively; this guide links to module docs as they appear.
- Existing data is preserved; changes to the DB are additive/non-destructive unless the
  user explicitly approves otherwise in a module.

---

## 4. Module status

| Module | Description | Status |
|---|---|---|
| M1 | Baseline ERD + DB audit doc (`docs/DATABASE.md`) | ✅ done (revised to live schema per decision) |
| M2 | Laravel scaffold + connect to existing (live) DB; source-of-truth resolved | ✅ done |
| M3 | Eloquent models (mirror of live source-of-truth DB) | ✅ done |
| M4 | Non-destructive migrations (FKs, audit_logs, approval_histories) | ✅ done |
| M5 | Auth API (Sanctum) | ✅ done |
| M6 | Users API (CRUD, policy, activation, password reset, audit) | ✅ done |
| M7 | Roles & Assignments API (role catalog, user parameter/category assignments) | ✅ done |
| M8 | Parameters & Categories API (read + admin write, policies, audit) | ✅ done |
| M9 | Reporting Periods API (lifecycle state machine, deadlines, dup-guard) | ✅ done |
| M10 | Monthly Data API (access-scoped entry, typed validation, transactional upsert, submit) | ✅ done |
| M11 | Approval Workflow (token+email flow, decision trail, state-machine alignment) | ✅ done |
| M12 | Notifications (queued mailables, retries, deadline reminders + scheduler) | ✅ done |
| M13 | Reports (PDF) | ✅ done |