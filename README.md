# MUWASCO Monthly Performance Reporting System

PHP 8 + MySQL (XAMPP) reporting application with a clean backend/frontend separation and a Tailwind CSS build.

## Architecture

```
monthly_report/
├── backend/                  # All PHP business logic (never web-accessible)
│   ├── app/
│   │   ├── Controllers/      # HTTP dispatch (MonthlyDataController)
│   │   ├── Services/         # Business rules (MonthlyDataService, EmailService)
│   │   ├── Repositories/     # Persistence (Models/MonthlyDataModel)
│   │   ├── Validators/       # Input validation (MonthlyDataValidator)
│   │   ├── Middleware/       # CsrfMiddleware
│   │   └── Helpers/          # Env, Logger, csrf_helpers, AuthFunctions, RoleFunctions
│   ├── bootstrap/app.php     # Single entry include: session, headers, errors, DB
│   └── config/               # database.php, mail.php, app.php (all from .env)
│
├── frontend/                 # Presentation only
│   ├── src/
│   │   ├── layouts/          # app-layout.php (shared shell)
│   │   ├── pages/            # dashboard & page views rendered by controllers
│   │   ├── components/       # sidebar.php, header.php (+ legacy sidebar-legacy.php)
│   │   ├── css/              # tailwind.css source
│   │   └── js/               # app.js
│
├── public/                   # Web-servable assets only
│   ├── index.php             # Front controller / router (legacy-URL compatible)
│   └── assets/               # css/, js/, images/
│
├── database/
│   ├── migrations/
│   ├── backups/
│   └── schema/maggie_monthlyreport.sql
│
├── tests/                    # PHPUnit
├── storage/logs/
├── .env                      # credentials (never commit) — see .env.example
├── composer.json             # PSR-4: App\ => backend/app/
├── package.json              # Tailwind build: frontend/src/css → public/assets/css
└── tailwind.config.js
```

**Request flow:** `*.php` entry point (root, URL-preserving) or `public/index.php` router → `backend/bootstrap/app.php` → auth/CSRF checks → controller logic → `backend/app` services/models → view in `frontend/src/pages` → rendered inside `frontend/src/layouts/app-layout.php`.

## Setup

```bash
composer install          # PHP deps + autoloader
cp .env.example .env      # then fill DB + SMTP credentials
npm install
npm run build             # Tailwind → public/assets/css/app.css
npm run watch             # during development
```

Serve via XAMPP Apache pointing at the project root (current, URL-preserving), or point a vhost docroot at `public/` and use `public/index.php` as the front controller. Dev alternative: `php -S localhost:8000 -t public public/index.php`.

## Tests

```bash
composer test             # or: vendor/bin/phpunit
```

## URLs (unchanged by the refactor)

`/index.php` (dashboard), `/login.php`, `/logout.php`, `/add_data.php`, `/months.php`, `/reports.php`, `/monthly_reports.php`, `/assignment.php`, `/user_management.php`
