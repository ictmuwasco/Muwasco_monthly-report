<?php

namespace App\Validators;
/**
 * app/Validators/MonthlyDataValidator.php
 * Server-side validation of monthly values against each parameter's
 * declared data_type ('number','text','currency','percentage') and required flag.
 */

class MonthlyDataValidator
{
    /**
     * Validate posted values keyed by parameter CODE.
     * @parammysqli $conn
     * @return array [ok => bool, errors => [code => message]]
     */
    public static function validateCodes(\mysqli $conn, array $data): array
    {
        if (!$data) return ['ok' => true, 'errors' => []];

        // Resolve codes to parameters with type info
        $codes = array_keys($data);
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $types = str_repeat('s', count($codes));

        $stmt = $conn->prepare("SELECT code, label, data_type, required FROM parameters WHERE code IN ($placeholders)");
        $stmt->bind_param($types, ...$codes);
        $stmt->execute();
        $params = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $p) {
            $params[$p['code']] = $p;
        }
        $stmt->close();

        $errors = [];
        foreach ($data as $code => $value) {
            $value = trim((string) $value);
            if ($value === '' || $value === '-') continue; // empty handled by section checks

            $p = $params[$code] ?? null;
            if (!$p) { $errors[$code] = 'Unknown parameter code.'; continue; }

            $err = self::checkValue((string)$p['data_type'], (string)$p['label'] ?: $code, $value);
            if ($err !== null) $errors[$code] = $err;
        }

        return ['ok' => !$errors, 'errors' => $errors];
    }

    /**
     * Pure validation rule for a single value against a declared type.
     * @return string|null Error message, or null when valid.
     */
    public static function checkValue(string $data_type, string $label, string $value): ?string
    {
        switch ($data_type) {
            case 'number':
                if (!is_numeric($value)) {
                    return $label . ' must be a number.';
                }
                break;
            case 'currency':
                $clean = str_replace([',', 'KSh', 'KES', ' '], '', $value);
                if (!is_numeric($clean)) {
                    return $label . ' must be a currency amount.';
                }
                break;
            case 'percentage':
                $clean = rtrim($value, '%');
                if (!is_numeric($clean)) {
                    return $label . ' must be a percentage.';
                } elseif ((float)$clean < 0 || (float)$clean > 100) {
                    return $label . ' must be between 0 and 100.';
                }
                break;
            case 'text':
            default:
                if (mb_strlen($value) > 2000) {
                    return $label . ' is too long (max 2000 characters).';
                }
        }
        return null;
    }
}
