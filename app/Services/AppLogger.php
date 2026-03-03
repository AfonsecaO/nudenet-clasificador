<?php

namespace App\Services;

/**
 * Logger estructurado de aplicación (errores, advertencias, diagnóstico).
 * Destino: tmp/logs/app.log en formato JSON Lines.
 * No sustituye a LogService (log de usuario/dashboard).
 */
class AppLogger
{
    private const DEFAULT_FILENAME = 'app.log';

    private static function logDir(): string
    {
        $dir = __DIR__ . '/../../tmp/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function logPath(): string
    {
        return rtrim(self::logDir(), "/\\") . DIRECTORY_SEPARATOR . self::DEFAULT_FILENAME;
    }

    private static function write(string $level, string $message, array $context = []): void
    {
        $payload = [
            'ts' => date('c'),
            'level' => $level,
            'message' => $message,
        ];
        if (!empty($context)) {
            $payload['context'] = $context;
        }
        $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line !== false) {
            @file_put_contents(self::logPath(), $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }
}
