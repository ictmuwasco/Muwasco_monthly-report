# User Management (M6)

Status: implemented and tested (`backend-laravel`).

## Endpoints (`/api/v1/users`, all `auth:sanctum`)
| Method | Path | Authorization | Notes |
|---|---|---|---|
| GET | `/users` | any authenticated | Paginated (max 100/page); `search`, `role`, `is_active` filters. |
| POST | `/users` | **admin only** | Validates: unique `username` (alpha_dash, ≤100), optional unique `email`, min-8 password, `role` in (admin,user). Password hashed on write. |
| GET | `/users/{user}` | self or admin | Single user. |
| PATCH | `/users/{user}` | **admin only** | `username` and `password` are `prohibited` (immutable — historical attribution stability; use the password endpoint). |
| POST | `/users/{user}/deactivate` | **admin only** | Soft-delete semantics: `is_active=false` + all tokens revoked. **An admin cannot deactivate their own account.** 409 if already inactive. |
| POST | `/users/{user}/activate` | **admin only** | Re-enables a deactivated account. |
| POST | `/users/{user}/password` | self or admin | `password` + `confirmed`, min 8. Revokes all tokens (forces re-login everywhere). |

## Implementation
- `App\Policies\UserPolicy` — the security boundary (frontend hiding is only UX).
- `App\Http\Requests\StoreUserRequest` / `UpdateUserRequest` — validation; passwords never
  survive in raw form after validation.
- `App\Services\AuditLogger` — writes append-only `audit_logs` rows
  (`user.created`, `user.updated`, `user.activated`, `user.deactivated`, `user.password_reset`)
  with actor, entity, old/new values (sanitized), IP, user agent.
- `App\Models\User` — `UPDATED_AT = null` (live `users` has only `created_at`); `$fillable`
  set (no mass-assignment surprises).

## Tests
`tests/Feature/UsersTest.php` (in-memory SQLite — live DB never touched): 16 tests covering
authorization (403s), CRUD, duplicate/weak-password rejection, immutable username/password,
self-deactivation prevention, activation lifecycle, password reset (self/admin/other),
token revocation, search/filter listing, and audit-log contents (no password material).
