<?php
/**
 * app/Middleware/CsrfMiddleware.php — CSRF token generation & validation.
 *
 * Usage in forms:
 *   <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
 *
 * Usage on POST handling:
 *   CsrfMiddleware::verifyOrDie();  // or verifyOrFail() for JSON endpoints
 */

class CsrfMiddleware
{
    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Session must be started before CSRF token generation.');
        }
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /** Validate a supplied token. */
    public static function check(?string $supplied): bool
    {
        $token = self::token();
        return is_string($supplied)
            && strlen($supplied) >= 64
            && hash_equals($token, $supplied);
    }

    /** Check the token from the current request; dies with safe message on failure. */
    public static function verifyOrDie(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return; // Only state-changing (POST) requests are verified here.
        }
        $supplied = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!self::check(is_string($supplied) ? $supplied : null)) {
            http_response_code(419);
            error_log(sprintf('[CSRF] Token mismatch user=%s ip=%s uri=%s',
                $_SESSION['user_id'] ?? 'guest',
                $_SERVER['REMOTE_ADDR'] ?? '?',
                $_SERVER['REQUEST_URI'] ?? '?'
            ));
            die('Security verification failed. Please go back, refresh the page and try again.');
        }
    }

    /** JSON-friendly variant: returns [ok, payload]. */
    public static function verifyOrFail(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return [true, []];
        }
        $supplied = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (self::check(is_string($supplied) ? $supplied : null)) {
            return [true, []];
        }
        return [false, ['error' => 'CSRF token mismatch', 'code' => 419]];
    }
}

/** Convenience helper for views. */
function csrf_token(): string
{
    return CsrfMiddleware::token();
}

/** Convenience helper: returns the hidden input HTML for forms. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}
