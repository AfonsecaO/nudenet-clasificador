<?php

namespace App\Services;

/**
 * Configuración global del servidor MariaDB/MySQL para almacenamiento interno.
 * Se guarda en config/mysql_app.json (editable desde el menú "Parametrización global" en Workspaces).
 * Si no existe el JSON, se usan variables de entorno MYSQL_APP_*.
 */
class MysqlAppConfig
{
    private static string $configPath = '';

    private static function getConfigPath(): string
    {
        if (self::$configPath !== '') {
            return self::$configPath;
        }
        self::$configPath = rtrim(__DIR__ . '/../../config', "/\\") . DIRECTORY_SEPARATOR . 'mysql_app.json';
        return self::$configPath;
    }

    /**
     * Devuelve la configuración: host, port, user, password, database.
     * Primero intenta cargar desde config/mysql_app.json; si no existe, usa getenv('MYSQL_APP_*').
     *
     * @return array{host: string, port: int, user: string, password: string, database: string}
     */
    public static function get(): array
    {
        $path = self::getConfigPath();
        if (is_file($path)) {
            $json = @file_get_contents($path);
            if ($json !== false) {
                $data = @json_decode($json, true);
                if (is_array($data)) {
                    return [
                        'host' => (string) ($data['host'] ?? getenv('MYSQL_APP_HOST') ?: '127.0.0.1'),
                        'port' => (int) ($data['port'] ?? getenv('MYSQL_APP_PORT') ?: 3306),
                        'user' => (string) ($data['user'] ?? getenv('MYSQL_APP_USER') ?: ''),
                        'password' => (string) ($data['password'] ?? getenv('MYSQL_APP_PASS') ?: ''),
                        'database' => (string) ($data['database'] ?? getenv('MYSQL_APP_DATABASE') ?: 'clasificador'),
                        'connection_tested_ok' => !empty($data['connection_tested_ok']),
                    ];
                }
            }
        }
        return [
            'host' => getenv('MYSQL_APP_HOST') !== false ? (string) getenv('MYSQL_APP_HOST') : '127.0.0.1',
            'port' => (int) (getenv('MYSQL_APP_PORT') ?: '3306'),
            'user' => (string) (getenv('MYSQL_APP_USER') ?: ''),
            'password' => (string) (getenv('MYSQL_APP_PASS') ?: ''),
            'database' => (string) (getenv('MYSQL_APP_DATABASE') ?: 'clasificador'),
            'connection_tested_ok' => false,
        ];
    }

    /**
     * Guarda la configuración en config/mysql_app.json.
     *
     * @param array{host?: string, port?: int|string, user?: string, password?: string, database?: string} $data
     */
    public static function save(array $data): void
    {
        $path = self::getConfigPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $current = self::get();
        $merged = [
            'host' => isset($data['host']) ? (string) $data['host'] : $current['host'],
            'port' => isset($data['port']) ? (int) $data['port'] : $current['port'],
            'user' => isset($data['user']) ? (string) $data['user'] : $current['user'],
            'password' => isset($data['password']) ? (string) $data['password'] : $current['password'],
            'database' => isset($data['database']) ? (string) $data['database'] : $current['database'],
        ];
        if (array_key_exists('connection_tested_ok', $data)) {
            $merged['connection_tested_ok'] = (bool) $data['connection_tested_ok'];
        } elseif (isset($current['connection_tested_ok'])) {
            $merged['connection_tested_ok'] = $current['connection_tested_ok'];
        }
        $json = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json !== false) {
            file_put_contents($path, $json);
        }
    }
}
