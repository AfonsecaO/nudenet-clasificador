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
     * Devuelve el motor elegido: 'sqlite' o 'mysql'. Por defecto 'sqlite' si no existe el archivo.
     */
    public static function getStorageEngine(): string
    {
        $path = self::storageEnginePath();
        if (!is_file($path)) {
            return self::DRIVER_SQLITE;
        }
        $json = @file_get_contents($path);
        if ($json === false) {
            return self::DRIVER_SQLITE;
        }
        $data = @json_decode($json, true);
        if (!is_array($data) || !isset($data['driver'])) {
            return self::DRIVER_SQLITE;
        }
        $driver = strtolower(trim((string) $data['driver']));
        return $driver === self::DRIVER_MYSQL ? self::DRIVER_MYSQL : self::DRIVER_SQLITE;
    }

    /**
     * Persiste el motor elegido. Solo 'sqlite' o 'mysql'. Crea la carpeta database/ si no existe.
     */
    public static function setStorageEngine(string $driver): void
    {
        $driver = strtolower(trim($driver)) === self::DRIVER_MYSQL ? self::DRIVER_MYSQL : self::DRIVER_SQLITE;
        $dir = self::databaseDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = self::storageEnginePath();
        file_put_contents($path, json_encode(['driver' => $driver], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * Ruta absoluta del archivo SQLite único (database/clasificador.sqlite).
     */
    public static function sqlitePath(): string
    {
        return self::databaseDir() . DIRECTORY_SEPARATOR . 'clasificador.sqlite';
    }
}
