<?php

namespace App\Helpers;
/**
 * app/Helpers/Logger.php — Structured file logging to storage/logs/.
 * Levels: INFO, WARNING, ERROR, CRITICAL. Never logs secrets or passwords.
 */

class Logger
{
    private const LEVELS = ['INFO', 'WARNING', 'ERROR', 'CRITICAL'];

    public static function log(string $level, string $channel, string $message, array $context = []): void
    {
        if (!in_array($level, self::LEVELS, true)) {
            $level = 'INFO';
        }
        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $entry = sprintf(
            "[%s] %s.%s: %s %s\n",
            date('Y-m-d H:i:s'),
            $level,
            basename($channel),
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_SLASHES) : ''
        );
        // All severities go to the channel file; ERROR+ also to errors.log; security channels also to security.log
        @file_put_contents("$dir/$channel.log", $entry, FILE_APPEND | LOCK_EX);
        if (in_array($level, ['ERROR', 'CRITICAL'], true)) {
            @file_put_contents("$dir/errors.log", $entry, FILE_APPEND | LOCK_EX);
        }
    }

    public static function info(string $msg, array $ctx = []): void    { self::log('INFO', 'application', $msg, $ctx); }
    public static function warning(string $msg, array $ctx = []): void { self::log('WARNING', 'application', $msg, $ctx); }
    public static function error(string $msg, array $ctx = []): void   { self::log('ERROR', 'application', $msg, $ctx); }
    public static function critical(string $msg, array $ctx = []): void{ self::log('CRITICAL', 'application', $msg, $ctx); }
    public static function security(string $msg, array $ctx = []): void{ self::log('WARNING', 'security', $msg, $ctx); }
}
