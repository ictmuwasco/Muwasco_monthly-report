<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Versioned API (v1). Stateless resource endpoints are protected by auth:sanctum.
| Stateful (SPA) session routes use Cookie/Sanctum statefulApi (bootstrap/app.php).
|
*/

Route::prefix('v1')->group(function () {

    // ── Public auth endpoints ─────────────────────────────────────────────
    Route::post('/login', [App\Http\Controllers\Api\V1\AuthController::class, 'login'])
        ->middleware('throttle:login'); // named limiter: 5/min per username+IP
    Route::get('/sanctum/csrf-cookie', [App\Http\Controllers\Api\V1\AuthController::class, 'csrfCookie'])
        ->middleware('web');

    // ── Authenticated ─────────────────────────────────────────────────────
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [App\Http\Controllers\Api\V1\AuthController::class, 'logout']);
        Route::get('/me', [App\Http\Controllers\Api\V1\AuthController::class, 'me']);
        Route::post('/auth/change-password', [App\Http\Controllers\Api\V1\AuthController::class, 'changePassword'])
            ->middleware('throttle:6,1');

        // ── User management (mutations admin-only via UserPolicy) ─────────
        Route::get('/users', [App\Http\Controllers\Api\V1\UserController::class, 'index']);
        Route::post('/users', [App\Http\Controllers\Api\V1\UserController::class, 'store']);
        Route::get('/users/{user}', [App\Http\Controllers\Api\V1\UserController::class, 'show']);
        Route::patch('/users/{user}', [App\Http\Controllers\Api\V1\UserController::class, 'update']);
        Route::post('/users/{user}/deactivate', [App\Http\Controllers\Api\V1\UserController::class, 'deactivate']);
        Route::post('/users/{user}/activate', [App\Http\Controllers\Api\V1\UserController::class, 'activate']);
        Route::post('/users/{user}/password', [App\Http\Controllers\Api\V1\UserController::class, 'resetPassword']);

        // ── Roles catalog + per-user data-access assignments (M7) ─────────
        Route::get('/roles', [App\Http\Controllers\Api\V1\RoleController::class, 'index']);
        Route::get('/roles/{role}', [App\Http\Controllers\Api\V1\RoleController::class, 'show']);

        Route::get('/users/{user}/assignments', [App\Http\Controllers\Api\V1\AssignmentController::class, 'index']);
        Route::put('/users/{user}/parameters', [App\Http\Controllers\Api\V1\AssignmentController::class, 'syncParameters']);
                Route::put('/users/{user}/categories', [App\Http\Controllers\Api\V1\AssignmentController::class, 'syncCategories']);

        // ── Parameters & Categories (M8) ─────────────────────────────────
        Route::apiResource('parameters', App\Http\Controllers\Api\V1\ParameterController::class)->only(['index', 'show', 'store', 'update']);
        Route::apiResource('parameter-categories', App\Http\Controllers\Api\V1\ParameterCategoryController::class)->only(['index', 'show', 'store', 'update']);

        // ── Reporting Periods (M9) — lifecycle-managed `months` table ────
        Route::apiResource('reporting-periods', App\Http\Controllers\Api\V1\ReportingPeriodController::class)
            ->only(['index', 'show', 'store', 'update'])
            ->parameters(['reporting-periods' => 'period']);
        Route::post('/reporting-periods/{period}/status', [App\Http\Controllers\Api\V1\ReportingPeriodController::class, 'transition']);

        // ── Monthly Data Entry (M10) ─────────────────────────────────────
        Route::get('/reporting-periods/{period}/data-entry', [App\Http\Controllers\Api\V1\MonthlyDataController::class, 'index']);
        Route::put('/reporting-periods/{period}/monthly-data', [App\Http\Controllers\Api\V1\MonthlyDataController::class, 'save']);
        Route::post('/reporting-periods/{period}/submit', [App\Http\Controllers\Api\V1\MonthlyDataController::class, 'submit']);

        // ── Approval Workflow (M11, authenticated) ───────────────────────
        Route::get('/reporting-periods/{period}/approvals', [App\Http\Controllers\Api\V1\ApprovalController::class, 'index']);
        Route::post('/reporting-periods/{period}/approvals', [App\Http\Controllers\Api\V1\ApprovalController::class, 'store']);
        Route::post('/reporting-periods/{period}/approvals/{approval}/decide', [App\Http\Controllers\Api\V1\ApprovalController::class, 'decide']);

        // ── Reports (M13) — monthly monitoring report: JSON preview + PDF ─────
        Route::get('/reports/preview', [App\Http\Controllers\Api\V1\ReportController::class, 'preview']);
        Route::get('/reports/pdf', [App\Http\Controllers\Api\V1\ReportController::class, 'pdf']);
    });

    // ── Approval Workflow (M11, public emailed-token flow) ───────────────
    Route::middleware('throttle:10,1')->group(function () {
        Route::get('/approval-requests/{token}', [App\Http\Controllers\Api\V1\ApprovalTokenController::class, 'show']);
        Route::post('/approval-requests/{token}/decide', [App\Http\Controllers\Api\V1\ApprovalTokenController::class, 'decide']);
    });

});