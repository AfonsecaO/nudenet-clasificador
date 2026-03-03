<?php

namespace App\Services;

/**
 * Configuración global del motor de almacenamiento (una sola vez para toda la app).
 * Todo vive bajo la carpeta database/ en la raíz del proyecto.
 */
class StorageEngineConfig
{
    private const STORAGE_ENGINE_FILE = 'storage_engine.json';
    private const DRIVER_SQLITE = 'sqlite';
    private const DRIVER_MYSQL = 'mysql';

    public static function databaseDir(): string
    {
        $dir = __DIR__ . '/../../database';
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dir);
    }

    public static function storageEnginePath(): string
    {
        return self::databaseDir() . DIRECTORY_SEPARATOR . self::STORAGE_ENGINE_FILE;
    }

    public static function storageEngineFileExists(): bool
    {
        $path = self::storageEnginePath();
        return is_file($path);
    }

    /**
     * Lee el JSON completo del archivo de configuración.
     */
    private static function readData(): array
    {
        $path = self::storageEnginePath();
        if (!is_file($path)) {
            return ['driver' => self::DRIVER_SQLITE];
        }
        $json = @file_get_contents($path);
        if ($json === false) {
            return ['driver' => self::DRIVER_SQLITE];
        }
        $data = @json_decode($json, true);
        return is_array($data) ? $data : ['driver' => self::DRIVER_SQLITE];
    }

    /**
     * Persiste el JSON completo (preserva claves existentes como registros_descarga).
     */
    private static function writeData(array $data): void
    {
        $dir = self::databaseDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = self::storageEnginePath();
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * Devuelve el motor elegido: 'sqlite' o 'mysql'. Por defecto 'sqlite' si no existe el archivo.
     */
    public static function getStorageEngine(): string
    {
        $data = self::readData();
        if (!isset($data['driver'])) {
            return self::DRIVER_SQLITE;
        }
        $driver = strtolower(trim((string) $data['driver']));
        return $driver === self::DRIVER_MYSQL ? self::DRIVER_MYSQL : self::DRIVER_SQLITE;
    }

    /**
     * Persiste el motor elegido. Solo 'sqlite' o 'mysql'. Preserva otras claves (ej. registros_descarga).
     */
    public static function setStorageEngine(string $driver): void
    {
        $driver = strtolower(trim($driver)) === self::DRIVER_MYSQL ? self::DRIVER_MYSQL : self::DRIVER_SQLITE;
        $data = self::readData();
        $data['driver'] = $driver;
        if (!isset($data['registros_descarga'])) {
            $data['registros_descarga'] = 1;
        }
        if (!isset($data['registros_moderacion'])) {
            $data['registros_moderacion'] = 20;
        }
        self::writeData($data);
    }

    /**
     * Número de imágenes a procesar por petición de moderación (global). Default 20, mínimo 1, máximo 100.
     */
    public static function getRegistrosModeracion(): int
    {
        $data = self::readData();
        $n = isset($data['registros_moderacion']) ? (int) $data['registros_moderacion'] : 20;
        return max(1, min(100, $n));
    }

    /**
     * Persiste el número de imágenes por lote de moderación.
     */
    public static function setRegistrosModeracion(int $n): void
    {
        $n = max(1, min(100, $n));
        $data = self::readData();
        $data['registros_moderacion'] = $n;
        self::writeData($data);
    }

    /**
     * Número de registros a procesar por petición de descarga (global). Default 1, mínimo 1, máximo 1000.
     */
    public static function getRegistrosDescarga(): int
    {
        $data = self::readData();
        $n = isset($data['registros_descarga']) ? (int) $data['registros_descarga'] : 1;
        return max(1, min(1000, $n));
    }

    /**
     * Persiste el número de registros por petición de descarga.
     */
    public static function setRegistrosDescarga(int $n): void
    {
        $n = max(1, min(1000, $n));
        $data = self::readData();
        $data['registros_descarga'] = $n;
        self::writeData($data);
    }

    /**
     * Ruta absoluta del archivo SQLite único (database/clasificador.sqlite).
     */
    public static function sqlitePath(): string
    {
        return self::databaseDir() . DIRECTORY_SEPARATOR . 'clasificador.sqlite';
    }

    /**
     * Lee la sección AWS del archivo de configuración (credenciales Rekognition, etc.).
     * @return array{key?:string, secret?:string, region?:string, version?:string, min_confidence?:int|float}
     */
    public static function getAwsConfig(): array
    {
        $data = self::readData();
        return isset($data['aws']) && is_array($data['aws']) ? $data['aws'] : [];
    }

    /**
     * Persiste la sección AWS en el archivo de configuración (preserva el resto de claves).
     */
    public static function setAwsConfig(array $aws): void
    {
        $data = self::readData();
        $data['aws'] = $aws;
        self::writeData($data);
    }
}
