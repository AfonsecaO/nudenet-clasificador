<?php

namespace App\Services;

use PDO;

class SqliteSchema
{
    private const META_MIGRATION_DETECT_ONLY_V1 = 'migration_detect_only_v1';

    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->query("PRAGMA table_info(" . $table . ")");
        $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
        foreach ($rows as $r) {
            if (isset($r['name']) && strtolower((string)$r['name']) === strtolower($column)) {
                return true;
            }
        }
        return false;
    }

    public static function ensure(PDO $pdo): void
    {
        // Meta
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS meta (
                key TEXT PRIMARY KEY,
                value TEXT
            )
        ");

        // Configuración de la app (persistida en SQLite)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS app_config (
                key TEXT PRIMARY KEY,
                value TEXT,
                updated_at TEXT
            )
        ");

        // Estado de tablas (procesamiento)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tables_state (
                tabla TEXT PRIMARY KEY,
                ultimo_id INTEGER DEFAULT 0,
                max_id INTEGER DEFAULT 0,
                faltan_registros INTEGER DEFAULT 1,
                ultima_actualizacion TEXT,
                ultima_actualizacion_contador TEXT
            )
        ");

        // Índice snapshot de tablas
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tables_index (
                tabla TEXT PRIMARY KEY,
                max_id INTEGER DEFAULT 0
            )
        ");

        // Imágenes
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS images (
                ruta_relativa TEXT PRIMARY KEY,
                ruta_completa TEXT,
                ruta_carpeta TEXT,
                archivo TEXT,
                content_md5 TEXT,

                indexada INTEGER DEFAULT 1,
                indexada_en TEXT,
                actualizada_en TEXT,
                mtime INTEGER,
                tamano INTEGER,
                seen_run INTEGER DEFAULT 0,

                clasif_estado TEXT DEFAULT 'pending',
                safe REAL,
                unsafe REAL,
                resultado TEXT,
                clasif_error TEXT,
                clasif_en TEXT,

                detect_requerida INTEGER DEFAULT 0,
                detect_estado TEXT DEFAULT 'na',
                detect_error TEXT,
                detect_en TEXT
            )
        ");

        // Migración ligera: si la tabla existía sin content_md5, agregar columna
        if (!self::hasColumn($pdo, 'images', 'content_md5')) {
            // Nota: ALTER TABLE es idempotente gracias al chequeo anterior
            $pdo->exec("ALTER TABLE images ADD COLUMN content_md5 TEXT");
        }

        // Carpetas (agregado)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS folders (
                ruta_carpeta TEXT PRIMARY KEY,
                nombre TEXT,
                search_key TEXT,
                total_imagenes INTEGER DEFAULT 0,
                actualizada_en TEXT
            )
        ");

        // Migración ligera: si la tabla existía sin search_key, agregar columna
        if (!self::hasColumn($pdo, 'folders', 'search_key')) {
            $pdo->exec("ALTER TABLE folders ADD COLUMN search_key TEXT");
        }

        // Detecciones (normalizado)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS detections (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                image_ruta_relativa TEXT NOT NULL,
                label TEXT NOT NULL,
                score REAL NOT NULL,
                x1 INTEGER,
                y1 INTEGER,
                x2 INTEGER,
                y2 INTEGER,
                FOREIGN KEY(image_ruta_relativa) REFERENCES images(ruta_relativa) ON DELETE CASCADE
            )
        ");

        // Índices
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_ruta_carpeta ON images(ruta_carpeta)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_archivo ON images(archivo)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_resultado ON images(resultado)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_clasif_estado ON images(clasif_estado)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_scores ON images(safe, unsafe)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_detect_estado ON images(detect_estado)");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_images_content_md5 ON images(content_md5)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_mtime ON images(mtime)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_detections_label_score ON detections(label, score)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_detections_image ON detections(image_ruta_relativa)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_folders_search_key ON folders(search_key)");

        // Migración: nuevo flujo "solo /detect"
        // - Marcar como pendiente de detección a registros antiguos con detect_estado='na'
        // - Forzar detect_requerida=1 para que stats/pipeline reflejen "detect para todas"
        try {
            $done = self::metaGet($pdo, self::META_MIGRATION_DETECT_ONLY_V1, null);
            if ($done === null || (string)$done !== '1') {
                $pdo->beginTransaction();
                try {
                    $pdo->exec("
                        UPDATE images
                        SET
                          detect_requerida = 1,
                          detect_estado = 'pending'
                        WHERE detect_estado IS NULL OR detect_estado = 'na'
                    ");
                    $pdo->commit();
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                self::metaSet($pdo, self::META_MIGRATION_DETECT_ONLY_V1, '1');
            }
        } catch (\Throwable $e) {
            // No bloquear la app por una migración ligera
        }

        // Rellenar search_key en folders existentes (una sola vez)
        try {
            $backfillDone = self::metaGet($pdo, 'folders_search_key_backfill', '0');
            if ((string)$backfillDone !== '1') {
                $rows = $pdo->query("
                    SELECT ruta_carpeta, nombre FROM folders
                    WHERE (search_key IS NULL OR TRIM(COALESCE(search_key,'')) = '')
                ")->fetchAll(\PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    $stmt = $pdo->prepare("UPDATE folders SET search_key = :sk WHERE ruta_carpeta = :ruta");
                    foreach ($rows as $r) {
                        $ruta = (string)($r['ruta_carpeta'] ?? '');
                        $nombre = (string)($r['nombre'] ?? '');
                        if ($nombre === '' && $ruta !== '') {
                            $nombre = basename(str_replace('\\', '/', trim($ruta, '/')));
                        }
                        $stmt->execute([
                            ':sk' => StringNormalizer::toSearchKey($ruta . ' ' . $nombre),
                            ':ruta' => $ruta
                        ]);
                    }
                }
                self::metaSet($pdo, 'folders_search_key_backfill', '1');
            }
        } catch (\Throwable $e) {
            // No bloquear la app
        }
    }

    public static function metaGet(PDO $pdo, string $key, $default = null)
    {
        $stmt = $pdo->prepare('SELECT value FROM meta WHERE key = :k');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch();
        return $row ? $row['value'] : $default;
    }

    public static function metaSet(PDO $pdo, string $key, $value): void
    {
        $stmt = $pdo->prepare('INSERT INTO meta(key, value) VALUES(:k, :v) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
        $stmt->execute([':k' => $key, ':v' => (string)$value]);
    }
}

