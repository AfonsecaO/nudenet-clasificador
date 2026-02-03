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
        $pdo = SqliteConnection::get();
        SqliteSchema::ensure($pdo);

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
        $pdo = SqliteConnection::get();
        SqliteSchema::ensure($pdo);

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
        $pdo = SqliteConnection::get();
        SqliteSchema::ensure($pdo);
        $stmt = $pdo->prepare('SELECT value FROM app_config WHERE key = :k');
        $stmt->execute([':k' => $parametro]);
        $v = $stmt->fetchColumn();
        return ($v === false) ? null : (string)$v;
    }

    public static function set(string $parametro, $valor): void
    {
        $pdo = SqliteConnection::get();
        SqliteSchema::ensure($pdo);
        $stmt = $pdo->prepare("
            INSERT INTO app_config(key, value, updated_at)
            VALUES(:k, :v, :t)
            ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at
        ");
        $stmt->execute([
            ':k' => $parametro,
            ':v' => (string)$valor,
            ':t' => date('Y-m-d H:i:s')
        ]);
    }

    public static function setMany(array $pairs): void
    {
        $pdo = SqliteConnection::get();
        SqliteSchema::ensure($pdo);
        $pdo->beginTransaction();
        try {
            foreach ($pairs as $k => $v) {
                if (!is_string($k) || $k === '') continue;
                self::set($k, $v);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
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
