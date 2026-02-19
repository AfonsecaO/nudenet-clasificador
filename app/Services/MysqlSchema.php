<?php

namespace App\Services;

use PDO;

class MysqlSchema
{
    private const META_MIGRATION_DETECT_ONLY_V1 = 'migration_detect_only_v1';
    private const DEFAULT_SLUG = 'default';

    private static function quoteId(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private static function createIndexIfNotExists(PDO $pdo, string $tableName, string $indexName, string $columns, bool $unique): void
    {
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $tableNameTrim = trim($tableName, '`');
        $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND INDEX_NAME = :idx LIMIT 1');
        $stmt->execute([':db' => $db, ':t' => $tableNameTrim, ':idx' => $indexName]);
        if ($stmt->fetchColumn()) {
            return;
        }
        $type = $unique ? 'UNIQUE INDEX' : 'INDEX';
        $indexNameQuoted = self::quoteId($indexName);
        $sql = "CREATE {$type} {$indexNameQuoted} ON {$tableName} ({$columns})";
        try {
            $pdo->exec($sql);
        } catch (\Throwable $e) {
            // Ignorar si el índice ya existe
        }
    }

    private static function hasColumn(PDO $pdo, string $tableName, string $column): bool
    {
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $tableNameTrim = trim($tableName, '`');
        $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1');
        $stmt->execute([':db' => $db, ':t' => $tableNameTrim, ':c' => $column]);
        return (bool) $stmt->fetchColumn();
    }

    public static function ensure(PDO $pdo): void
    {
        $meta = self::quoteId('meta');
        $appConfig = self::quoteId('app_config');
        $tablesState = self::quoteId('tables_state');
        $tablesIndex = self::quoteId('tables_index');
        $imagesTable = self::quoteId('images');
        $foldersTable = self::quoteId('folders');
        $detTable = self::quoteId('detections');

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$meta} (
                workspace_slug VARCHAR(191) NOT NULL,
                `key` VARCHAR(255) NOT NULL,
                value TEXT,
                PRIMARY KEY (workspace_slug, `key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::createIndexIfNotExists($pdo, $meta, 'idx_meta_ws', 'workspace_slug', false);
        self::createIndexIfNotExists($pdo, $meta, 'idx_meta_key', '`key`(191)', false);

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$appConfig} (
                workspace_slug VARCHAR(191) NOT NULL,
                `key` VARCHAR(255) NOT NULL,
                value TEXT,
                updated_at DATETIME NULL,
                PRIMARY KEY (workspace_slug, `key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::createIndexIfNotExists($pdo, $appConfig, 'idx_app_config_ws', 'workspace_slug', false);
        self::createIndexIfNotExists($pdo, $appConfig, 'idx_app_config_key', '`key`(191)', false);
        self::createIndexIfNotExists($pdo, $appConfig, 'uk_app_config_ws_key', 'workspace_slug, `key`(191)', true);

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$tablesState} (
                workspace_slug VARCHAR(191) NOT NULL,
                tabla VARCHAR(255) NOT NULL,
                ultimo_id INT DEFAULT 0,
                max_id INT DEFAULT 0,
                faltan_registros INT DEFAULT 1,
                ultima_actualizacion DATETIME NULL,
                ultima_actualizacion_contador DATETIME NULL,
                PRIMARY KEY (workspace_slug, tabla)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::createIndexIfNotExists($pdo, $tablesState, 'idx_tables_state_ws', 'workspace_slug', false);
        self::createIndexIfNotExists($pdo, $tablesState, 'idx_tables_state_tabla', 'tabla(191)', false);

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$tablesIndex} (
                workspace_slug VARCHAR(191) NOT NULL,
                tabla VARCHAR(255) NOT NULL,
                max_id INT DEFAULT 0,
                PRIMARY KEY (workspace_slug, tabla)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::createIndexIfNotExists($pdo, $tablesIndex, 'idx_tables_index_ws', 'workspace_slug', false);
        self::createIndexIfNotExists($pdo, $tablesIndex, 'idx_tables_index_tabla', 'tabla(191)', false);

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$imagesTable} (
                workspace_slug VARCHAR(191) NOT NULL,
                ruta_relativa VARCHAR(191) NOT NULL,
                ruta_completa TEXT,
                ruta_carpeta VARCHAR(191),
                archivo VARCHAR(255),
                content_md5 VARCHAR(32),
                indexada TINYINT DEFAULT 1,
                indexada_en DATETIME NULL,
                actualizada_en DATETIME NULL,
                mtime BIGINT NULL,
                tamano INT NULL,
                seen_run INT DEFAULT 0,
                clasif_estado VARCHAR(50) DEFAULT 'pending',
                safe DOUBLE NULL,
                unsafe DOUBLE NULL,
                resultado VARCHAR(50) NULL,
                clasif_error TEXT NULL,
                clasif_en DATETIME NULL,
                detect_requerida TINYINT DEFAULT 0,
                detect_estado VARCHAR(50) DEFAULT 'na',
                detect_error TEXT NULL,
                detect_en DATETIME NULL,
                PRIMARY KEY (workspace_slug, ruta_relativa)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        if (!self::hasColumn($pdo, $imagesTable, 'content_md5')) {
            $pdo->exec("ALTER TABLE {$imagesTable} ADD COLUMN content_md5 VARCHAR(32) NULL");
        }
        if (!self::hasColumn($pdo, $imagesTable, 'raw_md5')) {
            $pdo->exec("ALTER TABLE {$imagesTable} ADD COLUMN raw_md5 VARCHAR(32) NULL");
            self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_raw_md5', 'raw_md5', false);
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$foldersTable} (
                workspace_slug VARCHAR(191) NOT NULL,
                ruta_carpeta VARCHAR(191) NOT NULL,
                nombre VARCHAR(255) NULL,
                search_key VARCHAR(255) NULL,
                total_imagenes INT DEFAULT 0,
                actualizada_en DATETIME NULL,
                PRIMARY KEY (workspace_slug, ruta_carpeta)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        if (!self::hasColumn($pdo, $foldersTable, 'search_key')) {
            $pdo->exec("ALTER TABLE {$foldersTable} ADD COLUMN search_key VARCHAR(255) NULL");
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$detTable} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                workspace_slug VARCHAR(191) NOT NULL,
                image_ruta_relativa VARCHAR(191) NOT NULL,
                label VARCHAR(255) NOT NULL,
                score DOUBLE NOT NULL,
                x1 INT NULL,
                y1 INT NULL,
                x2 INT NULL,
                y2 INT NULL,
                INDEX idx_detections_ws (workspace_slug),
                INDEX idx_detections_ws_image (workspace_slug, image_ruta_relativa),
                INDEX idx_detections_label_score (label(191), score),
                INDEX idx_detections_image_label_score (image_ruta_relativa, label(191), score),
                INDEX idx_detections_label_score_image (label(191), score, image_ruta_relativa)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_ws', 'workspace_slug', false);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_ws_ruta_carpeta', 'workspace_slug, ruta_carpeta', false);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_ruta_carpeta', 'ruta_carpeta', false);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_ws_detect_estado_ruta', 'workspace_slug, detect_estado, ruta_relativa', false);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_detect_estado', 'detect_estado', false);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_ruta_relativa', 'ruta_relativa', false);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_ws_detect_requerida_estado', 'workspace_slug, detect_requerida, detect_estado', false);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_detect_requerida', 'detect_requerida', false);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_ws_clasif_estado_ruta', 'workspace_slug, clasif_estado, ruta_relativa', false);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_clasif_estado', 'clasif_estado', false);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_archivo', 'archivo(191)', false);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_resultado', 'resultado', false);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_scores', 'safe, unsafe', false);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_seen_run', 'seen_run', false);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_ws_content_md5', 'workspace_slug, content_md5', true);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_content_md5', 'content_md5', false);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_mtime', 'mtime', false);
        self::createIndexIfNotExists($pdo, $foldersTable, 'idx_folders_ws', 'workspace_slug', false);
        self::createIndexIfNotExists($pdo, $foldersTable, 'idx_folders_ws_search_key', 'workspace_slug, search_key(191)', false);
        self::createIndexIfNotExists($pdo, $foldersTable, 'idx_folders_search_key', 'search_key(191)', false);
        self::createIndexIfNotExists($pdo, $foldersTable, 'idx_folders_total_ruta', 'total_imagenes, ruta_carpeta', false);
        self::createIndexIfNotExists($pdo, $foldersTable, 'idx_folders_total_imagenes', 'total_imagenes', false);
        self::createIndexIfNotExists($pdo, $foldersTable, 'idx_folders_ruta_carpeta', 'ruta_carpeta', false);
        self::createIndexIfNotExists($pdo, $foldersTable, 'idx_folders_search_key_total_ruta', 'search_key(191), total_imagenes, ruta_carpeta', false);
        self::createIndexIfNotExists($pdo, $detTable, 'idx_detections_image_ruta_relativa', 'image_ruta_relativa', false);
        self::createIndexIfNotExists($pdo, $detTable, 'idx_detections_label', 'label(191)', false);
        self::createIndexIfNotExists($pdo, $detTable, 'idx_detections_score', 'score', false);

        $slug = AppConnection::currentSlug() ?? self::DEFAULT_SLUG;

        try {
            $done = self::metaGet($pdo, self::META_MIGRATION_DETECT_ONLY_V1, null, $slug);
            if ($done === null || (string) $done !== '1') {
                $alreadyInTransaction = $pdo->inTransaction();
                if (!$alreadyInTransaction) {
                    $pdo->beginTransaction();
                }
                try {
                    $stmt = $pdo->prepare("UPDATE {$imagesTable} SET detect_requerida = 1, detect_estado = 'pending' WHERE workspace_slug = :ws AND (detect_estado IS NULL OR detect_estado = 'na')");
                    $stmt->execute([':ws' => $slug]);
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

        try {
            $backfillDone = self::metaGet($pdo, 'folders_search_key_backfill', '0', $slug);
            if ((string) $backfillDone !== '1') {
                $stmt = $pdo->prepare("SELECT ruta_carpeta, nombre FROM {$foldersTable} WHERE workspace_slug = :ws AND (search_key IS NULL OR TRIM(COALESCE(search_key,'')) = '')");
                $stmt->execute([':ws' => $slug]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    $upd = $pdo->prepare("UPDATE {$foldersTable} SET search_key = :sk WHERE workspace_slug = :ws AND ruta_carpeta = :ruta");
                    foreach ($rows as $r) {
                        $ruta = (string) ($r['ruta_carpeta'] ?? '');
                        $nombre = (string) ($r['nombre'] ?? '');
                        if ($nombre === '' && $ruta !== '') {
                            $nombre = basename(str_replace('\\', '/', trim($ruta, '/')));
                        }
                        $upd->execute([
                            ':sk' => StringNormalizer::toSearchKey($ruta . ' ' . $nombre),
                            ':ws' => $slug,
                            ':ruta' => $ruta,
                        ]);
                    }
                }
                self::metaSet($pdo, 'folders_search_key_backfill', '1', $slug);
            }
        } catch (\Throwable $e) {
            // No bloquear la app
        }
    }

    public static function metaGet(PDO $pdo, string $key, $default = null, ?string $workspaceSlug = null): mixed
    {
        $ws = $workspaceSlug ?? AppConnection::currentSlug() ?? self::DEFAULT_SLUG;
        $meta = self::quoteId('meta');
        $stmt = $pdo->prepare("SELECT value FROM {$meta} WHERE workspace_slug = :ws AND `key` = :k");
        $stmt->execute([':ws' => $ws, ':k' => $key]);
        $row = $stmt->fetch();
        return $row ? $row['value'] : $default;
    }

    public static function metaSet(PDO $pdo, string $key, string $value, ?string $workspaceSlug = null): void
    {
        $ws = $workspaceSlug ?? AppConnection::currentSlug() ?? self::DEFAULT_SLUG;
        $meta = self::quoteId('meta');
        $stmt = $pdo->prepare("INSERT INTO {$meta} (workspace_slug, `key`, value) VALUES(:ws, :k, :v) ON DUPLICATE KEY UPDATE value = VALUES(value)");
        $stmt->execute([':ws' => $ws, ':k' => $key, ':v' => $value]);
    }
}
