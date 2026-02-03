<?php

namespace App\Services;

use PDO;

class SqliteConnection
{
    private static ?PDO $pdo = null;
    private static ?string $pdoWorkspace = null;

    public static function path(): string
    {
        $ws = WorkspaceService::current();
        if ($ws === null) {
            // Fallback legacy (solo para casos antes de seleccionar workspace)
            $dbDir = __DIR__ . '/../../tmp/db';
            if (!is_dir($dbDir)) {
                @mkdir($dbDir, 0755, true);
            }
            return $dbDir . '/clasificador.sqlite';
        }

        WorkspaceService::ensureStructure($ws);
        return WorkspaceService::paths($ws)['dbPath'];
    }

    public static function get(): PDO
    {
        $ws = WorkspaceService::current();
        $wsKey = $ws ?? '__legacy__';

        if (self::$pdo instanceof PDO && self::$pdoWorkspace === $wsKey) {
            return self::$pdo;
        }

        // Si cambió de workspace, crear nueva conexión
        self::$pdo = null;
        self::$pdoWorkspace = $wsKey;

        $path = self::path();
        $dsn = 'sqlite:' . $path;
        $pdo = new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        // PRAGMAs recomendadas
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA temp_store = MEMORY');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        self::$pdo = $pdo;
        return self::$pdo;
    }

    /**
     * Ajustes temporales para acelerar migración masiva.
     */
    public static function tuneForBulkLoad(PDO $pdo): void
    {
        $pdo->exec('PRAGMA synchronous = OFF');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA temp_store = MEMORY');
    }

    public static function tuneForRuntime(PDO $pdo): void
    {
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}

