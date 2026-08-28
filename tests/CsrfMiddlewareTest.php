<?php

namespace Tests;

use App\Middleware\CsrfMiddleware;
use PHPUnit\Framework\TestCase;

class CsrfMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        unset($_SESSION['csrf_token']);
        unset($_POST['csrf_token']);
    }

    public function testTokenIsGeneratedAndStable(): void
    {
        $t1 = CsrfMiddleware::token();
        $t2 = CsrfMiddleware::token();
        $this->assertSame($t1, $t2, 'Token must be stable within a session');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $t1);
    }

    public function testTokenRegeneratesAfterClearing(): void
    {
        $t1 = CsrfMiddleware::token();
        unset($_SESSION['csrf_token']);
        $t2 = CsrfMiddleware::token();
        $this->assertNotSame($t1, $t2);
    }

    public function testCsrfFieldContainsHiddenInput(): void
    {
        CsrfMiddleware::token(); // ensure class (and helper functions) are loaded
        $field = csrf_field();
        $this->assertStringContainsString('<input', $field);
        $this->assertStringContainsString('name="csrf_token"', $field);
        $this->assertStringContainsString(CsrfMiddleware::token(), $field);
    }

    public function testCheckAcceptsValidToken(): void
    {
        $this->assertTrue(CsrfMiddleware::check(CsrfMiddleware::token()));
    }

    public function testCheckRejectsMissingOrWrongToken(): void
    {
        $this->assertFalse(CsrfMiddleware::check(null));
        $this->assertFalse(CsrfMiddleware::check(''));
        $this->assertFalse(CsrfMiddleware::check(str_repeat('a', 64)));
        $this->assertFalse(CsrfMiddleware::check(CsrfMiddleware::token() . 'x'));
    }

    public function testTokenThrowsWithoutSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $this->expectException(\RuntimeException::class);
        try {
            CsrfMiddleware::token();
        } finally {
            @session_start(); // restore for tearDown of other tests
        }
    }
}
