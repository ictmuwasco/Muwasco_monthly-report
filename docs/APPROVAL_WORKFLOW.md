# Approval Workflow (M11)

> **Status:** ✅ done · 106 tests total (296 assertions) · live DB intact

## What it does
Implements the manager review/approval workflow on top of the `month_approvals` and
`approval_histories` tables (created additively in M2/M4), aligned with the M9 period
state machine.

## Workflow
```
period: submitted ──▶ (admin requests review) ──▶ under_review
                                                      │
        ┌─────────────────────────────────────────────┤
        ▼                                             ▼
  ALL approvals approved                       ANY approval rejected
        │                                             │
        ▼                                             ▼
  period: approved                          period: changes_requested
                                     (resubmit via M10 submit action)
```

1. **Admin requests review** for a manager role (`technical_manager` or `commercial_manager`,
   the two legacy approver roles) once the period is `submitted`. The first request moves the
   period to `under_review`; further reviewers can be added while `under_review`.
   A secure 40-char random token is generated — **only its SHA-256 hash is stored**, with a
   7-day expiry — and an `ApprovalRequested` mailable is sent.
2. **The manager decides** by clicking the emailed link (public token endpoints, no login,
   rate-limited 10/min), **or an admin records the decision** on their behalf
   (`approved_by_user_id` = admin). Token decisions leave `approved_by_user_id` NULL.
3. **Every action writes an append-only `approval_histories` row**
   (notified/approved/rejected, prev/new status, actor, comment).
4. Period status auto-refreshes: all approvals approved → `approved`;
   any rejection → `changes_requested` (rejection reason stored).

## Endpoints

### Authenticated (`auth:sanctum`, admin for writes)
| Method | Path | Notes |
|---|---|---|
| GET | `/reporting-periods/{period}/approvals` | Approval rows (token hidden) + full history trail |
| POST | `/reporting-periods/{period}/approvals` | Body `{ manager_role, notify_email? }`. Dup (period, role) → 422. Only for `submitted`/`under_review` periods |
| POST | `/reporting-periods/{period}/approvals/{approval}/decide` | Body `{ action: approve\|reject, comment? }`. Rejection requires a comment |

### Public emailed-token flow (throttle 10/min)
| Method | Path | Notes |
|---|---|---|
| GET | `/approval-requests/{token}` | Preview the review request |
| POST | `/approval-requests/{token}/decide` | Body `{ action, comment? }` — approve or request changes |

## Security / integrity guards (all tested)
- **Token hashing:** only SHA-256 stored; raw token exists solely in the email.
  Never exposed in any API JSON (`assertArrayNotHasKey`).
- **One decision per approval** — second decide attempt → 422.
- **Expired token → 410**; unknown token → 404.
- **Closed periods accept no decisions** → 422.
- **Cross-period IDOR** — deciding an approval under a different period → 404.
- **Duplicate review requests** blocked (validation + DB UNIQUE(month_id, manager_role) backstop).
- **Rejection requires a reason.**
- Recipients: explicit `notify_email` (practical today — the live `users.role` enum only has
  admin/user, so no manager accounts exist yet) or active users carrying the role later.

## Files
`app/Services/ApprovalService.php` · `app/Http/Controllers/Api/V1/ApprovalController.php` ·
`app/Http/Controllers/Api/V1/ApprovalTokenController.php` · `app/Mail/ApprovalRequested.php` ·
`resources/views/emails/approval-requested.blade.php` · `MonthApproval::histories()` relation.

## Verification
- **17 new tests**; full suite **106 passed (296 assertions)**. `Mail::fake()` in tests.
- Coverage: request/duplicate/role-validation/403; approve & reject via admin; reject-needs-comment;
  decide-twice; closed-period block; cross-period IDOR; token approve/reject/once/410/404;
  auto period-status transitions; history rows; hidden token.
- Live DB intact: users=10, months=14, monthly_data=2206, month_approvals=0, approval_histories=0.
