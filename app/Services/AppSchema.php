<?php

namespace App\Services;

use PDO;

class AppSchema
{
    /**
     * Asegura que existan todas las tablas del esquema (SQLite o MySQL según conexión).
     */
    public static function ensure(PDO $pdo): void
    {
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'mysql') {
            MysqlSchema::ensure($pdo);
            return;
        }
        SqliteSchema::ensure($pdo);
    }

    /**
     * Obtiene un valor de la tabla meta (compatible SQLite y MySQL). Requiere workspace_slug.
     */
    public static function metaGet(PDO $pdo, string $key, $default = null, ?string $workspaceSlug = null): mixed
    {
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'mysql') {
            return MysqlSchema::metaGet($pdo, $key, $default, $workspaceSlug);
        }
        return SqliteSchema::metaGet($pdo, $key, $default, $workspaceSlug);
    }

    /**
     * Escribe un valor en la tabla meta (compatible SQLite y MySQL). Requiere workspace_slug.
     */
    public static function metaSet(PDO $pdo, string $key, string $value, ?string $workspaceSlug = null): void
    {
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'mysql') {
            MysqlSchema::metaSet($pdo, $key, $value, $workspaceSlug);
            return;
        }
        SqliteSchema::metaSet($pdo, $key, $value, $workspaceSlug);
    }
}
