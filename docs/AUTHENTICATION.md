# Authentication (M5)

Status: implemented and tested (`backend-laravel`).

## Stack
- **Laravel Sanctum** — dual mode:
  - **SPA (stateful)**: `GET /api/v1/sanctum/csrf-cookie` sets the XSRF cookie; login
    authenticates the web session via `statefulApi` middleware (recommended for the React frontend).
  - **Bearer token**: login also returns a `personal_access_token` for API clients/scripts.

## Endpoints (`routes/api.php`)
| Method | Path | Auth | Notes |
|---|---|---|---|
| POST | `/api/v1/login` | public | `throttle:5,1` (5 attempts/min). Accepts `username` (or email) + `password`. Rejects inactive accounts (`is_active=false`). Returns `{ user, token }` — the password hash is never exposed. |
| GET | `/api/v1/sanctum/csrf-cookie` | public | Sets the CSRF cookie for the SPA. |
| POST | `/api/v1/logout` | auth:sanctum | Revokes the bearer token used, logs out the web session, invalidates + regenerates session/CSRF. |
| GET | `/api/v1/me` | auth:sanctum | Returns the authenticated user payload. |

## Identity source
The live legacy `users` table is authoritative: `username`, `email`, `role`
enum(`admin`,`user`), `is_active`, `full_name`. No new identity columns were added.

## Implementation notes / bugs fixed during M5
- `App\Models\User` uses `HasApiTokens` (required for Sanctum token issuance).
- Logout revokes the token by parsing the `id|plain` bearer string directly (reliable
  regardless of guard resolution) and logs out only the `web` guard (Sanctum's guard
  has no `logout()`).
- Feature tests (`tests/Feature/AuthTest.php`) run against in-memory SQLite — the live
  MySQL DB is never touched by tests.

## Testing
`php artisan test` — 8 passed (17 assertions), covering: successful login, wrong
password, inactive account, missing fields, unauthenticated `/me`, `/me` + logout with
a token, and token revocation after logout.
