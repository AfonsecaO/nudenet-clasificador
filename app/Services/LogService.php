<?php

namespace App\Services;

/**
 * Servicio de log persistente (archivo) para el dashboard.
 *
 * Formato: JSON Lines (una entrada JSON por línea).
 */
class LogService
{
    private const DEFAULT_FILENAME = 'dashboard.log';

    private static function logsDir(): string
    {
        $ws = WorkspaceService::current();
        if ($ws === null) {
            return __DIR__ . '/../../tmp/logs';
        }
        WorkspaceService::ensureStructure($ws);
        return WorkspaceService::paths($ws)['logsDir'];
    }

    private static function logPath(string $filename = self::DEFAULT_FILENAME): string
    {
        return rtrim(self::logsDir(), "/\\") . DIRECTORY_SEPARATOR . $filename;
    }

    private static function ensureDir(): void
    {
        $dir = self::logsDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    /**
     * @param array $entry keys sugeridas: type, message, time, ts, meta
     */
    public static function append(array $entry, string $filename = self::DEFAULT_FILENAME): void
    {
        self::ensureDir();

        $type = isset($entry['type']) ? strtolower(trim((string)$entry['type'])) : 'info';
        if (!in_array($type, ['info', 'success', 'warning', 'error'], true)) $type = 'info';

        $message = isset($entry['message']) ? (string)$entry['message'] : '';
        $message = trim($message);
        if ($message === '') return;
        if (strlen($message) > 5000) {
            $message = substr($message, 0, 5000) . '…';
        }

        $ts = date('c');
        $time = date('H:i:s');

        $payload = [
            'ts' => $ts,
            'time' => $time,
            'type' => $type,
            'message' => $message,
        ];

        if (isset($entry['meta']) && is_array($entry['meta'])) {
            $payload['meta'] = $entry['meta'];
        }

        $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) return;
        $line .= "\n";

        @file_put_contents(self::logPath($filename), $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Retorna las últimas N entradas del archivo (si existe).
     * Lee el archivo y devuelve explícitamente los últimos $limit registros (orden cronológico).
     */
    public static function tail(int $limit = 20, string $filename = self::DEFAULT_FILENAME): array
    {
        $limit = max(1, min(200, (int)$limit));
        $path = self::logPath($filename);
        if (!is_file($path)) return [];

        // Leer contenido (máx. 1MB para no cargar logs enormes en memoria)
        $maxBytes = 1024 * 1024;
        $size = filesize($path);
        if ($size <= 0) return [];
        $offset = $size > $maxBytes ? $size - $maxBytes : 0;
        $fh = @fopen($path, 'rb');
        if (!$fh) return [];
        try {
            if ($offset > 0) fseek($fh, $offset);
            $content = stream_get_contents($fh, $maxBytes);
        } finally {
            fclose($fh);
        }
        if ($content === false || $content === '') return [];

        // Si leímos desde el medio, descartar la primera línea (puede estar cortada)
        if ($offset > 0 && preg_match('/\n/', $content) === 1) {
            $firstNewline = strpos($content, "\n");
            $content = $firstNewline !== false ? substr($content, $firstNewline + 1) : $content;
        }

        $lines = array_filter(explode("\n", $content), function ($l) {
            return trim($l) !== '';
        });
        $lines = array_values($lines);
        $total = count($lines);
        $lines = $total > $limit ? array_slice($lines, -$limit) : $lines;

        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $j = json_decode($line, true);
            if (is_array($j) && isset($j['message'])) {
                $out[] = $j;
            } else {
                $out[] = ['ts' => null, 'time' => null, 'type' => 'info', 'message' => $line];
            }
        }
        return $out;
    }

    public static function clear(string $filename = self::DEFAULT_FILENAME): void
    {
        self::ensureDir();
        $path = self::logPath($filename);
        @file_put_contents($path, '', LOCK_EX);
    }
}

