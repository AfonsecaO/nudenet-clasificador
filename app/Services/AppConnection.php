<?php

namespace App\Services;

use PDO;

/**
 * Una sola conexión a la BD (SQLite en database/clasificador.sqlite o MySQL según database/storage_engine.json).
 * Todas las tablas son compartidas; cada fila identifica el workspace con la columna workspace_slug.
 */
class AppConnection
{
    private static ?PDO $pdo = null;
    private static string $currentDriver = 'sqlite';

    /**
     * Slug del workspace actual (cookie o ?workspace=). Para inyectar en consultas WHERE workspace_slug = :ws.
     */
    public static function currentSlug(): ?string
    {
        return WorkspaceService::current();
    }

    /**
     * Ruta del único archivo SQLite (database/clasificador.sqlite). Vacía si el motor es MySQL.
     */
    public static function path(): string
    {
        if (StorageEngineConfig::getStorageEngine() !== 'sqlite') {
            return '';
        }
        $path = StorageEngineConfig::sqlitePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $path;
    }

    /**
     * Nombre de tabla (sin prefijo; todas las tablas son compartidas con columna workspace_slug).
     */
    public static function table(string $name): string
    {
        if (self::$currentDriver === 'mysql') {
            return '`' . str_replace('`', '``', $name) . '`';
        }
        return $name;
    }

    public static function getCurrentDriver(): string
    {
        return self::$currentDriver;
    }

    /**
     * Conexión PDO única (una BD para toda la app).
     */
    public static function get(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $driver = StorageEngineConfig::getStorageEngine();

        if ($driver === 'mysql') {
            self::$currentDriver = 'mysql';
            $pdo = self::getMysqlPdo();
            if ($pdo !== null) {
                self::$pdo = $pdo;
                return $pdo;
            }
            self::$currentDriver = 'sqlite';
        }

        self::$currentDriver = 'sqlite';
        $path = self::path();
        if ($path === '') {
            throw new \RuntimeException('No se pudo determinar la ruta de la base de datos SQLite.');
        }
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA temp_store = MEMORY');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        self::$pdo = $pdo;
        return self::$pdo;
    }

    private static function getMysqlPdo(): ?PDO
    {
        $configFile = __DIR__ . '/../../config/mysql_app.php';
        if (!is_file($configFile)) {
            return null;
        }
        $config = require $configFile;
        if (!is_array($config) || empty($config['host']) || ($config['database'] ?? '') === '') {
            return null;
        }
        $host = (string) $config['host'];
        $port = (int) ($config['port'] ?? 3306);
        $dbname = (string) $config['database'];
        $user = (string) ($config['user'] ?? '');
        $pass = (string) ($config['password'] ?? '');
        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            return $pdo;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Indica si la tabla existe (compatible SQLite y MySQL).
     */
    public static function hasTable(PDO $pdo, string $tableName): bool
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (strtolower($driver) === 'mysql') {
            $nameForSchema = trim($tableName, '`');
            $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1');
            $stmt->execute([':t' => $nameForSchema]);
            return (bool) $stmt->fetchColumn();
        }
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:t LIMIT 1");
        $stmt->execute([':t' => $tableName]);
        return (bool) $stmt->fetchColumn();
    }

    public static function tuneForBulkLoad(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            return;
        }
        $pdo->exec('PRAGMA synchronous = OFF');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA temp_store = MEMORY');
    }

    public static function tuneForRuntime(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            return;
        }
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}
