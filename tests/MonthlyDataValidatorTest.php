<?php

namespace Tests;

use App\Validators\MonthlyDataValidator;
use PHPUnit\Framework\TestCase;

class MonthlyDataValidatorTest extends TestCase
{
    /* ── Pure rule tests (no DB) ─────────────────────────────── */

    public function testNumberAcceptsNumeric(): void
    {
        $this->assertNull(MonthlyDataValidator::checkValue('number', 'Units', '42.5'));
        $this->assertNull(MonthlyDataValidator::checkValue('number', 'Units', '-3'));
    }

    public function testNumberRejectsNonNumeric(): void
    {
        $this->assertStringContainsString('must be a number', MonthlyDataValidator::checkValue('number', 'Units', 'abc'));
    }

    public function testCurrencyAcceptsFormatted(): void
    {
        $this->assertNull(MonthlyDataValidator::checkValue('currency', 'Billing', '12,500'));
        $this->assertNull(MonthlyDataValidator::checkValue('currency', 'Billing', 'KSh 1,250,000'));
        $this->assertNull(MonthlyDataValidator::checkValue('currency', 'Billing', 'KES 900'));
    }

    public function testCurrencyRejectsGarbage(): void
    {
        $this->assertStringContainsString('currency', MonthlyDataValidator::checkValue('currency', 'Billing', 'N/A approx'));
    }

    public function testPercentageAcceptsRangeAndPercentSign(): void
    {
        $this->assertNull(MonthlyDataValidator::checkValue('percentage', 'UFW', '78.4'));
        $this->assertNull(MonthlyDataValidator::checkValue('percentage', 'UFW', '100%'));
        $this->assertNull(MonthlyDataValidator::checkValue('percentage', 'UFW', '0'));
    }

    public function testPercentageRejectsOutOfRange(): void
    {
        $this->assertStringContainsString('between 0 and 100', MonthlyDataValidator::checkValue('percentage', 'UFW', '178.4'));
        $this->assertStringContainsString('between 0 and 100', MonthlyDataValidator::checkValue('percentage', 'UFW', '-5'));
    }

    public function testTextLengthLimit(): void
    {
        $this->assertNull(MonthlyDataValidator::checkValue('text', 'Notes', str_repeat('a', 2000)));
        $this->assertStringContainsString('too long', MonthlyDataValidator::checkValue('text', 'Notes', str_repeat('a', 2001)));
    }

    public function testUnknownTypeDefaultsToTextRule(): void
    {
        $this->assertNull(MonthlyDataValidator::checkValue('mystery', 'X', 'anything goes'));
    }

    /* ── validateCodes against the live schema (read-only) ───── */

    public function testValidateCodesEmptyInputIsOk(): void
    {
        $conn = $this->mysqli();
        $r = MonthlyDataValidator::validateCodes($conn, []);
        $this->assertTrue($r['ok']);
        $conn->close();
    }

    public function testValidateCodesFlagsRealParameterViolations(): void
    {
        $conn = $this->mysqli();
        // Pick a real numeric parameter and feed it garbage
        $res = $conn->query("SELECT code, label FROM parameters WHERE data_type='number' AND required=1 LIMIT 1");
        $p = $res->fetch_assoc();
        if (!$p) { $this->markTestSkipped('No numeric parameters in DB'); }
        $r = MonthlyDataValidator::validateCodes($conn, [$p['code'] => 'not-a-number!!']);
        $this->assertFalse($r['ok']);
        $this->assertArrayHasKey($p['code'], $r['errors']);
        // And a valid value passes
        $r2 = MonthlyDataValidator::validateCodes($conn, [$p['code'] => '123.45']);
        $this->assertTrue($r2['ok'], 'Valid numeric rejected: ' . json_encode($r2['errors']));
        $conn->close();
    }

    public function testValidateCodesUnknownCode(): void
    {
        $conn = $this->mysqli();
        $r = MonthlyDataValidator::validateCodes($conn, ['__no_such_code__' => '1']);
        $this->assertFalse($r['ok']);
        $this->assertSame('Unknown parameter code.', $r['errors']['__no_such_code__']);
        $conn->close();
    }

    private function mysqli(): \mysqli
    {
        $env = parse_ini_file(dirname(__DIR__) . '/.env');
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        // Tests connect over TCP: XAMPP's socket isn't at PHP's default path.
        $host = ($env['DB_HOST'] ?? 'localhost') === 'localhost' ? '127.0.0.1' : $env['DB_HOST'];
        $conn = new \mysqli(
            $host,
            $env['DB_USER'] ?? 'root',
            $env['DB_PASS'] ?? '',
            $env['DB_NAME'] ?? 'maggie_monthlyreport',
            (int)($env['DB_PORT'] ?? 3306)
        );
        $conn->set_charset('utf8mb4');
        return $conn;
    }
}
