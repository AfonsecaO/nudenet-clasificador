<?php

namespace App\Services;

use PDO;

class SqliteSchema
{
    private const META_MIGRATION_DETECT_ONLY_V1 = 'migration_detect_only_v1';
    private const DEFAULT_SLUG = 'default';

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
        // Meta: (workspace_slug, key) PK
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS meta (
                workspace_slug TEXT NOT NULL,
                key TEXT NOT NULL,
                value TEXT,
                PRIMARY KEY (workspace_slug, key)
            )
        ");
        self::migrateAddWorkspaceSlug($pdo, 'meta', 'key');

        // app_config: (workspace_slug, key) PK
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS app_config (
                workspace_slug TEXT NOT NULL,
                key TEXT NOT NULL,
                value TEXT,
                updated_at TEXT,
                PRIMARY KEY (workspace_slug, key)
            )
        ");
        self::migrateAddWorkspaceSlug($pdo, 'app_config', 'key');

        // tables_state: (workspace_slug, tabla) PK
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tables_state (
                workspace_slug TEXT NOT NULL,
                tabla TEXT NOT NULL,
                ultimo_id INTEGER DEFAULT 0,
                max_id INTEGER DEFAULT 0,
                faltan_registros INTEGER DEFAULT 1,
                ultima_actualizacion TEXT,
                ultima_actualizacion_contador TEXT,
                PRIMARY KEY (workspace_slug, tabla)
            )
        ");
        self::migrateAddWorkspaceSlug($pdo, 'tables_state', 'tabla');

        // tables_index: (workspace_slug, tabla) PK
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tables_index (
                workspace_slug TEXT NOT NULL,
                tabla TEXT NOT NULL,
                max_id INTEGER DEFAULT 0,
                PRIMARY KEY (workspace_slug, tabla)
            )
        ");
        self::migrateAddWorkspaceSlug($pdo, 'tables_index', 'tabla');

        // images: (workspace_slug, ruta_relativa) PK
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS images (
                workspace_slug TEXT NOT NULL,
                ruta_relativa TEXT NOT NULL,
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
                detect_en TEXT,
                PRIMARY KEY (workspace_slug, ruta_relativa)
            )
        ");
        self::migrateAddWorkspaceSlug($pdo, 'images', 'ruta_relativa');

        if (!self::hasColumn($pdo, 'images', 'content_md5')) {
            $pdo->exec("ALTER TABLE images ADD COLUMN content_md5 TEXT");
        }
        if (!self::hasColumn($pdo, 'images', 'raw_md5')) {
            $pdo->exec("ALTER TABLE images ADD COLUMN raw_md5 TEXT");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_raw_md5 ON images(raw_md5)");
        }

        // folders: (workspace_slug, ruta_carpeta) PK
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS folders (
                workspace_slug TEXT NOT NULL,
                ruta_carpeta TEXT NOT NULL,
                nombre TEXT,
                search_key TEXT,
                total_imagenes INTEGER DEFAULT 0,
                actualizada_en TEXT,
                PRIMARY KEY (workspace_slug, ruta_carpeta)
            )
        ");
        self::migrateAddWorkspaceSlug($pdo, 'folders', 'ruta_carpeta');

        if (!self::hasColumn($pdo, 'folders', 'search_key')) {
            $pdo->exec("ALTER TABLE folders ADD COLUMN search_key TEXT");
        }

        // detections: id PK; workspace_slug para filtrar
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS detections (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                workspace_slug TEXT NOT NULL,
                image_ruta_relativa TEXT NOT NULL,
                label TEXT NOT NULL,
                score REAL NOT NULL,
                x1 INTEGER,
                y1 INTEGER,
                x2 INTEGER,
                y2 INTEGER
            )
        ");
        self::migrateAddWorkspaceSlug($pdo, 'detections', 'id', false);

        // Índices por workspace_slug e individuales por cada compuesto (PK u otros)
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_meta_ws ON meta(workspace_slug)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_meta_key ON meta(key)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_app_config_ws ON app_config(workspace_slug)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_app_config_key ON app_config(key)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tables_state_ws ON tables_state(workspace_slug)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tables_state_tabla ON tables_state(tabla)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tables_index_ws ON tables_index(workspace_slug)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tables_index_tabla ON tables_index(tabla)");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_ws ON images(workspace_slug)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_ws_ruta_carpeta ON images(workspace_slug, ruta_carpeta)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_ruta_carpeta ON images(ruta_carpeta)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_ws_detect_estado_ruta ON images(workspace_slug, detect_estado, ruta_relativa)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_detect_estado ON images(detect_estado)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_ruta_relativa ON images(ruta_relativa)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_ws_detect_requerida_estado ON images(workspace_slug, detect_requerida, detect_estado)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_detect_requerida ON images(detect_requerida)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_ws_clasif_estado_ruta ON images(workspace_slug, clasif_estado, ruta_relativa)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_clasif_estado ON images(clasif_estado)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_archivo ON images(archivo)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_resultado ON images(resultado)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_scores ON images(safe, unsafe)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_seen_run ON images(seen_run)");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_images_ws_content_md5 ON images(workspace_slug, content_md5)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_content_md5 ON images(content_md5)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_ws_raw_md5 ON images(workspace_slug, raw_md5)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_mtime ON images(mtime)");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_detections_ws ON detections(workspace_slug)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_detections_ws_image ON detections(workspace_slug, image_ruta_relativa)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_detections_image_ruta_relativa ON detections(image_ruta_relativa)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_detections_label_score ON detections(label, score)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_detections_label ON detections(label)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_detections_score ON detections(score)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_detections_image_label_score ON detections(image_ruta_relativa, label, score)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_detections_label_score_image ON detections(label, score, image_ruta_relativa)");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_folders_ws ON folders(workspace_slug)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_folders_ws_search_key ON folders(workspace_slug, search_key)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_folders_search_key ON folders(search_key)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_folders_total_ruta ON folders(total_imagenes, ruta_carpeta)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_folders_total_imagenes ON folders(total_imagenes)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_folders_ruta_carpeta ON folders(ruta_carpeta)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_folders_search_key_total_ruta ON folders(search_key, total_imagenes, ruta_carpeta)");

        // Migración: detect_only_v1 por workspace (meta ahora tiene workspace_slug)
        try {
            $ws = AppConnection::currentSlug();
            $slug = $ws ?? self::DEFAULT_SLUG;
            $done = self::metaGet($pdo, self::META_MIGRATION_DETECT_ONLY_V1, null, $slug);
            if ($done === null || (string)$done !== '1') {
                $alreadyInTransaction = $pdo->inTransaction();
                if (!$alreadyInTransaction) {
                    $pdo->beginTransaction();
                }
                try {
                    $pdo->exec("
                        UPDATE images
                        SET detect_requerida = 1, detect_estado = 'pending'
                        WHERE workspace_slug = " . $pdo->quote($slug) . " AND (detect_estado IS NULL OR detect_estado = 'na')
                    ");
                    if (!$alreadyInTransaction && $pdo->inTransaction()) {
                        $pdo->commit();
                    }
                } catch (\Throwable $e) {
                    if (!$alreadyInTransaction && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
                self::metaSet($pdo, self::META_MIGRATION_DETECT_ONLY_V1, '1', $slug);
            }
        } catch (\Throwable $e) {
            // No bloquear la app
        }

        // Rellenar search_key en folders (por workspace)
        try {
            $ws = AppConnection::currentSlug();
            $slug = $ws ?? self::DEFAULT_SLUG;
            $backfillDone = self::metaGet($pdo, 'folders_search_key_backfill', '0', $slug);
            if ((string)$backfillDone !== '1') {
                $stmt = $pdo->prepare("SELECT ruta_carpeta, nombre FROM folders WHERE workspace_slug = :ws AND (search_key IS NULL OR TRIM(COALESCE(search_key,'')) = '')");
                $stmt->execute([':ws' => $slug]);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    $upd = $pdo->prepare("UPDATE folders SET search_key = :sk WHERE workspace_slug = :ws AND ruta_carpeta = :ruta");
                    foreach ($rows as $r) {
                        $ruta = (string)($r['ruta_carpeta'] ?? '');
                        $nombre = (string)($r['nombre'] ?? '');
                        if ($nombre === '' && $ruta !== '') {
                            $nombre = basename(str_replace('\\', '/', trim($ruta, '/')));
                        }
                        $upd->execute([
                            ':sk' => StringNormalizer::toSearchKey($ruta . ' ' . $nombre),
                            ':ws' => $slug,
                            ':ruta' => $ruta
                        ]);
                    }
                }
                self::metaSet($pdo, 'folders_search_key_backfill', '1', $slug);
            }
        } catch (\Throwable $e) {
            // No bloquear la app
        }
    }

    /**
     * Migración: si la tabla existe pero sin columna workspace_slug, añadirla y opcionalmente índice único compuesto.
     */
    private static function migrateAddWorkspaceSlug(PDO $pdo, string $table, string $pkColumn, bool $uniqueComposite = true): void
    {
        $stmt = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($table));
        if (!$stmt || !$stmt->fetchColumn()) {
            return;
        }
        if (self::hasColumn($pdo, $table, 'workspace_slug')) {
            return;
        }
        $pdo->exec("ALTER TABLE " . $table . " ADD COLUMN workspace_slug TEXT NOT NULL DEFAULT '" . self::DEFAULT_SLUG . "'");
        if ($uniqueComposite) {
            $idxName = 'idx_' . $table . '_ws_' . $pkColumn;
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS {$idxName} ON {$table}(workspace_slug, " . $pkColumn . ")");
        } else {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$table}_ws ON {$table}(workspace_slug)");
        }
    }

    public static function metaGet(PDO $pdo, string $key, $default = null, ?string $workspaceSlug = null): mixed
    {
        $ws = $workspaceSlug ?? AppConnection::currentSlug() ?? self::DEFAULT_SLUG;
        $stmt = $pdo->prepare('SELECT value FROM meta WHERE workspace_slug = :ws AND key = :k');
        $stmt->execute([':ws' => $ws, ':k' => $key]);
        $row = $stmt->fetch();
        return $row ? $row['value'] : $default;
    }

    public static function metaSet(PDO $pdo, string $key, $value, ?string $workspaceSlug = null): void
    {
        $ws = $workspaceSlug ?? AppConnection::currentSlug() ?? self::DEFAULT_SLUG;
        $stmt = $pdo->prepare('INSERT INTO meta(workspace_slug, key, value) VALUES(:ws, :k, :v) ON CONFLICT(workspace_slug, key) DO UPDATE SET value=excluded.value');
        $stmt->execute([':ws' => $ws, ':k' => $key, ':v' => (string)$value]);
    }
}
