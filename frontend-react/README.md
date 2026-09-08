# MUWASCO Monthly Reporting — React Frontend

A React 18 + Tailwind CSS single-page application that talks to the Laravel API
in `../backend-laravel/` (which in turn reads/writes the live `maggie_monthlyreport`
MySQL database).

## Stack
- Vite (dev server + build)
- React 18 + React Router v6
- Tailwind CSS v3 (JIT)
- axios (Bearer-token auth against the Laravel Sanctum API)

## Pages
| Route | Access | Endpoints used |
|---|---|---|
| `/login` | public | `POST /login`, `GET /me`, `POST /logout` |
| `/` | any | `GET /reporting-periods` |
| `/periods` | admin | `GET/POST /reporting-periods`, `POST /reporting-periods/{id}/status` |
| `/data-entry` | any | `GET /reporting-periods/{id}/data-entry`, `PUT …/monthly-data`, `POST …/submit` |
| `/reports` | any | `GET /reports/preview`, `GET /reports/pdf` |
| `/approvals` | any | `GET/POST /reporting-periods/{id}/approvals`, `POST …/{approval}/decide` |
| `/users` | admin | `GET/POST /users`, `POST /users/{id}/activate\|deactivate\|password` |
| `/assignments` | admin | `GET /users/{id}/assignments`, `PUT /users/{id}/parameters\|categories` |
| `/parameters` | admin | `GET/POST/PATCH /parameters` |
| `/categories` | admin | `GET/POST/PATCH /parameter-categories` |

## Dev
Point the backend Laravel app at your database (it already is), then:

```bash
# 1. Start the Laravel API (from backend-laravel/)
php artisan serve --port=8000
# or via XAMPP: run composer dev / php -S localhost:8000 -t public

# 2. Start this React dev server
npm install
npm run dev          # http://localhost:5173  (proxies /api → :8000)
```

The Vite dev server proxies `/api` to `http://localhost:8000`, so the SPA and
API share an origin during development (no CORS). To point straight at the API
instead, set `VITE_USE_PROXY=false` and `VITE_API_URL` in `.env`.

## Build
```bash
npm run build        # outputs optimised static assets to dist/
npm run preview      # serve the production build
```

## Notes
- Auth uses Laravel Sanctum **bearer tokens** returned by `POST /api/v1/login`.
  The token is stored in `localStorage` and attached to every request.
- A global 401 interceptor clears the session and redirects to `/login`.