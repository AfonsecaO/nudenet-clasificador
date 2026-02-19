<?php

namespace App\Services;

class ConfigService
{
    // Requeridos "mínimos" por workspace (siempre)
    private static $parametrosRequeridos = [
        'WORKSPACE_MODE',          // images_only | db_and_images
        'CLASIFICADOR_BASE_URL',   // obligatorio
    ];
    
    private static $parametrosOpcionales = [
        // DB + schema (opcionales si WORKSPACE_MODE=images_only)
        'DB_HOST',
        'DB_PORT',
        'DB_NAME',
        'DB_USER',
        'DB_PASS',
        'TABLE_PATTERN',
        'PRIMARY_KEY',
        'CAMPO_IDENTIFICADOR',
        'CAMPO_USR_ID',
        'CAMPO_FECHA',
        'COLUMNAS_IMAGEN',
        'CAMPO_RESULTADO',
        'PATRON_MATERIALIZACION',
        'CLASIFICADOR_BASE_URL',
        // CSV de labels a IGNORAR en /detect (lista negra). Labels normalizados en MAYÚSCULAS.
        'DETECT_IGNORED_LABELS',

        // Deprecados (mantener para no romper setups antiguos; se limpian al guardar)
        'DIRECTORIO_IMAGENES',
        'UMBRAL_CLASIFICACION',
        'DETECT_UNSAFE_CLASSES',
    ];
    
    /**
     * Carga y valida la configuración persistida en SQLite (app_config)
     * @throws \Exception Si faltan parámetros requeridos
     */
    public static function cargarYValidar()
    {
        // Asegurar schema
        $pdo = AppConnection::get();
        AppSchema::ensure($pdo);

        $faltantes = self::faltantesRequeridos();
        if (!empty($faltantes)) {
            $mensaje = "Faltan los siguientes parámetros requeridos de configuración:\n\n";
            $mensaje .= implode("\n", array_map(function ($p) {
                return "  - $p";
            }, $faltantes));
            $mensaje .= "\n\nPor favor, completa la parametrización del sistema para continuar.";
            throw new \Exception($mensaje);
        }
    }
    
    public static function estaConfigurado(): bool
    {
        return empty(self::faltantesRequeridos());
    }

    public static function faltantesRequeridos(): array
    {
        $pdo = AppConnection::get();
        AppSchema::ensure($pdo);

        $faltantes = [];
        // Requeridos mínimos
        foreach (self::$parametrosRequeridos as $parametro) {
            $v = self::get($parametro);
            if ($v === null || trim((string)$v) === '') $faltantes[] = $parametro;
        }

        // Requeridos condicionales para modo DB
        $mode = self::getWorkspaceMode();
        if ($mode === 'db_and_images') {
            $reqDb = [
                'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER',
                'TABLE_PATTERN', 'PRIMARY_KEY', 'CAMPO_IDENTIFICADOR', 'CAMPO_USR_ID', 'CAMPO_FECHA', 'COLUMNAS_IMAGEN',
            ];
            foreach ($reqDb as $parametro) {
                $v = self::get($parametro);
                if ($v === null || trim((string)$v) === '') $faltantes[] = $parametro;
            }
        }

        return $faltantes;
    }

    public static function getWorkspaceMode(): string
    {
        $raw = self::get('WORKSPACE_MODE');
        $raw = is_string($raw) ? strtolower(trim($raw)) : '';
        if ($raw === 'db' || $raw === 'db_and_images' || $raw === 'database') return 'db_and_images';
        return 'images_only';
    }

    /**
     * Obtiene un parámetro requerido
     * @throws \Exception Si el parámetro no existe
     */
    public static function obtenerRequerido($parametro)
    {
        $v = self::get($parametro);
        if ($v === null || trim((string)$v) === '') {
            throw new \Exception("El parámetro requerido '$parametro' no está configurado. Completa la parametrización.");
        }
        return $v;
    }
    
    /**
     * Obtiene un parámetro opcional
     */
    public static function obtenerOpcional($parametro, $default = null)
    {
        $v = self::get($parametro);
        return ($v === null || trim((string)$v) === '') ? $default : $v;
    }

    public static function get(string $parametro): ?string
    {
        $pdo = AppConnection::get();
        AppSchema::ensure($pdo);
        $ws = AppConnection::currentSlug() ?? 'default';
        $t = AppConnection::table('app_config');
        $driver = AppConnection::getCurrentDriver();
        // Buscar por clave exacta primero
        $stmt = $pdo->prepare($driver === 'mysql'
            ? "SELECT value FROM {$t} WHERE workspace_slug = :ws AND `key` = :k LIMIT 1"
            : "SELECT value FROM {$t} WHERE workspace_slug = :ws AND key = :k LIMIT 1");
        $stmt->execute([':ws' => $ws, ':k' => $parametro]);
        $v = $stmt->fetchColumn();
        if ($v !== false) return (string)$v;
        // Compatibilidad: si no hay fila con clave exacta, buscar por clave canónica (evita duplicados por capitalización)
        $kCanonical = self::normalizarKey($parametro);
        if ($kCanonical === '') return null;
        $stmt2 = $pdo->prepare($driver === 'mysql'
            ? "SELECT value FROM {$t} WHERE workspace_slug = :ws AND UPPER(TRIM(`key`)) = :k LIMIT 1"
            : "SELECT value FROM {$t} WHERE workspace_slug = :ws AND UPPER(TRIM(key)) = :k LIMIT 1");
        $stmt2->execute([':ws' => $ws, ':k' => $kCanonical]);
        $v2 = $stmt2->fetchColumn();
        return ($v2 === false) ? null : (string)$v2;
    }

    /**
     * Clave canónica para app_config: mayúsculas y trim (evita duplicados por capitalización).
     */
    public static function normalizarKey(string $parametro): string
    {
        return strtoupper(trim($parametro));
    }

    public static function set(string $parametro, $valor): void
    {
        $pdo = AppConnection::get();
        AppSchema::ensure($pdo);
        $ws = AppConnection::currentSlug() ?? 'default';
        $t = AppConnection::table('app_config');
        $kCanonical = self::normalizarKey($parametro);
        if ($kCanonical === '') return;

        $driver = AppConnection::getCurrentDriver();
        $now = date('Y-m-d H:i:s');
        if ($driver === 'mysql') {
            $stmt = $pdo->prepare("
                INSERT INTO {$t} (workspace_slug, `key`, value, updated_at) VALUES (:ws, :k, :v, :t)
                ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO {$t} (workspace_slug, key, value, updated_at) VALUES (:ws, :k, :v, :t)
                ON CONFLICT(workspace_slug, key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at
            ");
        }
        $stmt->execute([
            ':ws' => $ws,
            ':k' => $kCanonical,
            ':v' => (string)$valor,
            ':t' => $now
        ]);

        // Eliminar filas duplicadas por distinta capitalización (misma clave lógica)
        if ($driver === 'mysql') {
            $del = $pdo->prepare("DELETE FROM {$t} WHERE workspace_slug = :ws AND `key` <> :k AND UPPER(TRIM(`key`)) = :k_canon");
            $del->execute([':ws' => $ws, ':k' => $kCanonical, ':k_canon' => $kCanonical]);
        } else {
            $del = $pdo->prepare("DELETE FROM {$t} WHERE workspace_slug = :ws AND key <> :k AND UPPER(TRIM(key)) = :k_canon");
            $del->execute([':ws' => $ws, ':k' => $kCanonical, ':k_canon' => $kCanonical]);
        }
    }

    public static function setMany(array $pairs): void
    {
        $pdo = AppConnection::get();
        AppSchema::ensure($pdo);
        $pdo->beginTransaction();
        try {
            foreach ($pairs as $k => $v) {
                if (!is_string($k) || $k === '') continue;
                self::set($k, $v);
            }
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Elimina filas duplicadas en app_config para un workspace (misma clave exacta o misma clave canónica).
     * Deja una sola fila por clave canónica (mayúsculas). Seguro aunque la tabla tenga filas duplicadas.
     */
    public static function dedupeAppConfigForWorkspace(string $workspaceSlug): void
    {
        $pdo = AppConnection::get();
        AppSchema::ensure($pdo);
        $t = AppConnection::table('app_config');
        $driver = AppConnection::getCurrentDriver();
        $keyCol = $driver === 'mysql' ? '`key`' : 'key';
        $stmt = $pdo->prepare("SELECT {$keyCol}, value, updated_at FROM {$t} WHERE workspace_slug = :ws ORDER BY updated_at DESC");
        $stmt->execute([':ws' => $workspaceSlug]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        // Una entrada por clave canónica; si hay varias filas con la misma clave, nos quedamos con la más reciente (updated_at DESC)
        $byCanonical = [];
        foreach ($rows as $r) {
            $k = (string)($r['key'] ?? '');
            $c = self::normalizarKey($k);
            if ($c === '') continue;
            if (!isset($byCanonical[$c])) {
                $byCanonical[$c] = ['value' => (string)($r['value'] ?? ''), 'updated_at' => (string)($r['updated_at'] ?? '')];
            }
        }
        $now = date('Y-m-d H:i:s');
        $pdo->beginTransaction();
        try {
            if ($driver === 'mysql') {
                $delAll = $pdo->prepare("DELETE FROM {$t} WHERE workspace_slug = :ws");
                $delAll->execute([':ws' => $workspaceSlug]);
                $ins = $pdo->prepare("INSERT INTO {$t} (workspace_slug, `key`, value, updated_at) VALUES (:ws, :k, :v, :t)");
            } else {
                $delAll = $pdo->prepare("DELETE FROM {$t} WHERE workspace_slug = :ws");
                $delAll->execute([':ws' => $workspaceSlug]);
                $ins = $pdo->prepare("INSERT INTO {$t} (workspace_slug, key, value, updated_at) VALUES (:ws, :k, :v, :t)");
            }
            foreach ($byCanonical as $canon => $one) {
                $ins->execute([':ws' => $workspaceSlug, ':k' => $canon, ':v' => $one['value'], ':t' => $one['updated_at'] ?: $now]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function getKeysRequeridas(): array
    {
        return self::$parametrosRequeridos;
    }

    public static function getKeysOpcionales(): array
    {
        return self::$parametrosOpcionales;
    }
}
