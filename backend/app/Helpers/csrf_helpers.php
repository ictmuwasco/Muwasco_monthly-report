<?php
/**
 * app/Helpers/csrf_helpers.php — global view helpers for CSRF.
 * Loaded via Composer "files" autoloading (see composer.json).
 */

function csrf_token(): string
{
    return \App\Middleware\CsrfMiddleware::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}
