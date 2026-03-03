<?php

namespace App\Services;

use PDO;

/**
 * Schema from scratch: normalized table and column names (snake_case, English).
 * No migrations; fresh project only.
 */
class SqliteSchema
{
    private const DEFAULT_SLUG = 'default';

    public static function ensure(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS workspace_meta (
                workspace_slug TEXT NOT NULL,
                key TEXT NOT NULL,
                value TEXT,
                PRIMARY KEY (workspace_slug, key)
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_workspace_meta_ws ON workspace_meta(workspace_slug)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_workspace_meta_key ON workspace_meta(key)");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS app_config (
                workspace_slug TEXT NOT NULL,
                key TEXT NOT NULL,
                value TEXT,
                updated_at TEXT,
                PRIMARY KEY (workspace_slug, key)
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_app_config_ws ON app_config(workspace_slug)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_app_config_key ON app_config(key)");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS table_states (
                workspace_slug TEXT NOT NULL,
                table_name TEXT NOT NULL,
                last_processed_id INTEGER DEFAULT 0,
                max_id INTEGER DEFAULT 0,
                has_pending INTEGER DEFAULT 1,
                last_updated_at TEXT,
                last_count_at TEXT,
                PRIMARY KEY (workspace_slug, table_name)
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_table_states_ws ON table_states(workspace_slug)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_table_states_table_name ON table_states(table_name)");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS table_indexes (
                workspace_slug TEXT NOT NULL,
                table_name TEXT NOT NULL,
                max_id INTEGER DEFAULT 0,
                PRIMARY KEY (workspace_slug, table_name)
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_table_indexes_ws ON table_indexes(workspace_slug)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_table_indexes_table_name ON table_indexes(table_name)");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS batch_claims (
                workspace_slug TEXT NOT NULL,
                table_name TEXT NOT NULL,
                id_min INTEGER NOT NULL,
                id_max INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'in_progress',
                claimed_at TEXT NOT NULL,
                completed_at TEXT,
                error_message TEXT,
                PRIMARY KEY (workspace_slug, table_name, id_min)
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_batch_claims_ws_table ON batch_claims(workspace_slug, table_name)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_batch_claims_status_claimed ON batch_claims(workspace_slug, table_name, status, claimed_at)");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS images (
                workspace_slug TEXT NOT NULL,
                relative_path TEXT NOT NULL,
                full_path TEXT,
                folder_path TEXT,
                filename TEXT,
                content_md5 TEXT,
                raw_md5 TEXT,
                is_indexed INTEGER DEFAULT 1,
                indexed_at TEXT,
                updated_at TEXT,
                file_mtime INTEGER,
                file_size INTEGER,
                scan_run INTEGER DEFAULT 0,
                moderation_analyzed_at TEXT,
                moderation_model_version TEXT,
                PRIMARY KEY (workspace_slug, relative_path)
            )
        ");
        self::addImagesModerationColumnsIfMissing($pdo);
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_ws ON images(workspace_slug)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_ws_folder_path ON images(workspace_slug, folder_path)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_folder_path ON images(folder_path)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_relative_path ON images(relative_path)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_filename ON images(filename)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_scan_run ON images(scan_run)");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_images_ws_content_md5 ON images(workspace_slug, content_md5)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_content_md5 ON images(content_md5)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_ws_raw_md5 ON images(workspace_slug, raw_md5)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_images_file_mtime ON images(file_mtime)");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS image_moderation_labels (
                workspace_slug TEXT NOT NULL,
                relative_path TEXT NOT NULL,
                taxonomy_level INTEGER NOT NULL,
                label_name TEXT NOT NULL,
                parent_name TEXT,
                confidence REAL,
                created_at TEXT,
                PRIMARY KEY (workspace_slug, relative_path, taxonomy_level, label_name)
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_iml_ws ON image_moderation_labels(workspace_slug)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_iml_ws_level ON image_moderation_labels(workspace_slug, taxonomy_level)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_iml_ws_label ON image_moderation_labels(workspace_slug, label_name)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_iml_ws_level_label ON image_moderation_labels(workspace_slug, taxonomy_level, label_name)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_iml_ws_label_conf ON image_moderation_labels(workspace_slug, label_name, confidence)");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS folders (
                workspace_slug TEXT NOT NULL,
                folder_path TEXT NOT NULL,
                name TEXT,
                search_key TEXT,
                image_count INTEGER DEFAULT 0,
                updated_at TEXT,
                PRIMARY KEY (workspace_slug, folder_path)
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_folders_ws ON folders(workspace_slug)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_folders_ws_search_key ON folders(workspace_slug, search_key)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_folders_search_key ON folders(search_key)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_folders_image_count_folder_path ON folders(image_count, folder_path)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_folders_image_count ON folders(image_count)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_folders_folder_path ON folders(folder_path)");
    }

    public static function metaGet(PDO $pdo, string $key, $default = null, ?string $workspaceSlug = null): mixed
    {
        $ws = $workspaceSlug ?? AppConnection::currentSlug() ?? self::DEFAULT_SLUG;
        $stmt = $pdo->prepare('SELECT value FROM workspace_meta WHERE workspace_slug = :ws AND key = :k');
        $stmt->execute([':ws' => $ws, ':k' => $key]);
        $row = $stmt->fetch();
        return $row ? $row['value'] : $default;
    }

    public static function metaSet(PDO $pdo, string $key, $value, ?string $workspaceSlug = null): void
    {
        $ws = $workspaceSlug ?? AppConnection::currentSlug() ?? self::DEFAULT_SLUG;
        $stmt = $pdo->prepare('INSERT INTO workspace_meta(workspace_slug, key, value) VALUES(:ws, :k, :v) ON CONFLICT(workspace_slug, key) DO UPDATE SET value=excluded.value');
        $stmt->execute([':ws' => $ws, ':k' => $key, ':v' => (string)$value]);
    }

    /**
     * Añade columnas de moderación a images si la tabla ya existía sin ellas (migración).
     */
    private static function addImagesModerationColumnsIfMissing(PDO $pdo): void
    {
        $stmt = $pdo->query('PRAGMA table_info(images)');
        $columns = [];
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $columns[$row['name']] = true;
        }
        if (!isset($columns['moderation_analyzed_at'])) {
            $pdo->exec('ALTER TABLE images ADD COLUMN moderation_analyzed_at TEXT');
        }
        if (!isset($columns['moderation_model_version'])) {
            $pdo->exec('ALTER TABLE images ADD COLUMN moderation_model_version TEXT');
        }
    }
}
