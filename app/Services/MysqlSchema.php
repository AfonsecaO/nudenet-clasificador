<?php

namespace App\Services;

use PDO;

/**
 * Schema from scratch: normalized table and column names (snake_case, English).
 * No migrations; fresh project only.
 */
class MysqlSchema
{
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
            // Ignore if index already exists
        }
    }

    public static function ensure(PDO $pdo): void
    {
        $meta = self::quoteId('workspace_meta');
        $appConfig = self::quoteId('app_config');
        $tableStates = self::quoteId('table_states');
        $tableIndexes = self::quoteId('table_indexes');
        $imagesTable = self::quoteId('images');
        $foldersTable = self::quoteId('folders');

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$meta} (
                workspace_slug VARCHAR(191) NOT NULL,
                `key` VARCHAR(255) NOT NULL,
                value TEXT,
                PRIMARY KEY (workspace_slug, `key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::createIndexIfNotExists($pdo, $meta, 'idx_workspace_meta_ws', 'workspace_slug', false);
        self::createIndexIfNotExists($pdo, $meta, 'idx_workspace_meta_key', '`key`(191)', false);

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

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$tableStates} (
                workspace_slug VARCHAR(191) NOT NULL,
                table_name VARCHAR(255) NOT NULL,
                last_processed_id INT DEFAULT 0,
                max_id INT DEFAULT 0,
                has_pending INT DEFAULT 1,
                last_updated_at DATETIME NULL,
                last_count_at DATETIME NULL,
                PRIMARY KEY (workspace_slug, table_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::createIndexIfNotExists($pdo, $tableStates, 'idx_table_states_ws', 'workspace_slug', false);
        self::createIndexIfNotExists($pdo, $tableStates, 'idx_table_states_table_name', 'table_name(191)', false);

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$tableIndexes} (
                workspace_slug VARCHAR(191) NOT NULL,
                table_name VARCHAR(255) NOT NULL,
                max_id INT DEFAULT 0,
                PRIMARY KEY (workspace_slug, table_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::createIndexIfNotExists($pdo, $tableIndexes, 'idx_table_indexes_ws', 'workspace_slug', false);
        self::createIndexIfNotExists($pdo, $tableIndexes, 'idx_table_indexes_table_name', 'table_name(191)', false);

        $batchClaims = self::quoteId('batch_claims');
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$batchClaims} (
                workspace_slug VARCHAR(191) NOT NULL,
                table_name VARCHAR(255) NOT NULL,
                id_min INT NOT NULL,
                id_max INT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'in_progress',
                claimed_at DATETIME NOT NULL,
                completed_at DATETIME NULL,
                error_message TEXT NULL,
                PRIMARY KEY (workspace_slug, table_name, id_min)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::createIndexIfNotExists($pdo, $batchClaims, 'idx_batch_claims_ws_table', 'workspace_slug, table_name(191)', false);
        self::createIndexIfNotExists($pdo, $batchClaims, 'idx_batch_claims_status_claimed', 'workspace_slug, table_name(191), status, claimed_at', false);

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$imagesTable} (
                workspace_slug VARCHAR(191) NOT NULL,
                relative_path VARCHAR(191) NOT NULL,
                full_path TEXT,
                folder_path VARCHAR(191),
                filename VARCHAR(255),
                content_md5 VARCHAR(32),
                raw_md5 VARCHAR(32),
                is_indexed TINYINT DEFAULT 1,
                indexed_at DATETIME NULL,
                updated_at DATETIME NULL,
                file_mtime BIGINT NULL,
                file_size INT NULL,
                scan_run INT DEFAULT 0,
                PRIMARY KEY (workspace_slug, relative_path)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_ws', 'workspace_slug', false);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_ws_folder_path', 'workspace_slug, folder_path', false);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_folder_path', 'folder_path', false);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_relative_path', 'relative_path', false);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_filename', 'filename(191)', false);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_scan_run', 'scan_run', false);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_ws_content_md5', 'workspace_slug, content_md5', true);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_content_md5', 'content_md5', false);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_ws_raw_md5', 'workspace_slug, raw_md5', false);
        self::createIndexIfNotExists($pdo, $imagesTable, 'idx_images_file_mtime', 'file_mtime', false);

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$foldersTable} (
                workspace_slug VARCHAR(191) NOT NULL,
                folder_path VARCHAR(191) NOT NULL,
                name VARCHAR(255) NULL,
                search_key VARCHAR(255) NULL,
                image_count INT DEFAULT 0,
                updated_at DATETIME NULL,
                PRIMARY KEY (workspace_slug, folder_path)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::createIndexIfNotExists($pdo, $foldersTable, 'idx_folders_ws', 'workspace_slug', false);
        self::createIndexIfNotExists($pdo, $foldersTable, 'idx_folders_ws_search_key', 'workspace_slug, search_key(191)', false);
        self::createIndexIfNotExists($pdo, $foldersTable, 'idx_folders_search_key', 'search_key(191)', false);
        self::createIndexIfNotExists($pdo, $foldersTable, 'idx_folders_image_count_folder_path', 'image_count, folder_path', false);
        self::createIndexIfNotExists($pdo, $foldersTable, 'idx_folders_image_count', 'image_count', false);
        self::createIndexIfNotExists($pdo, $foldersTable, 'idx_folders_folder_path', 'folder_path', false);
    }

    public static function metaGet(PDO $pdo, string $key, $default = null, ?string $workspaceSlug = null): mixed
    {
        $ws = $workspaceSlug ?? AppConnection::currentSlug() ?? self::DEFAULT_SLUG;
        $meta = self::quoteId('workspace_meta');
        $stmt = $pdo->prepare("SELECT value FROM {$meta} WHERE workspace_slug = :ws AND `key` = :k");
        $stmt->execute([':ws' => $ws, ':k' => $key]);
        $row = $stmt->fetch();
        return $row ? $row['value'] : $default;
    }

    public static function metaSet(PDO $pdo, string $key, string $value, ?string $workspaceSlug = null): void
    {
        $ws = $workspaceSlug ?? AppConnection::currentSlug() ?? self::DEFAULT_SLUG;
        $meta = self::quoteId('workspace_meta');
        $stmt = $pdo->prepare("INSERT INTO {$meta} (workspace_slug, `key`, value) VALUES(:ws, :k, :v) ON DUPLICATE KEY UPDATE value = VALUES(value)");
        $stmt->execute([':ws' => $ws, ':k' => $key, ':v' => $value]);
    }
}
