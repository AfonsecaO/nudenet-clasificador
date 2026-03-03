<?php

namespace App\Models;

use PDO;

/**
 * Modelo para la tabla image_moderation_labels (etiquetas de moderación por imagen).
 * taxonomy_level: 0 = safe, 1 = L1, 2 = L2, 3 = L3.
 */
class ImageModerationLabels
{
    private $pdo;
    private $tLabels;
    private $tImages;

    private function ws(): string
    {
        return \App\Services\AppConnection::currentSlug() ?? 'default';
    }

    public function __construct()
    {
        $this->pdo = \App\Services\AppConnection::get();
        \App\Services\AppSchema::ensure($this->pdo);
        $this->tLabels = \App\Services\AppConnection::table('image_moderation_labels');
        $this->tImages = \App\Services\AppConnection::table('images');
    }

    /**
     * Inserta o reemplaza las etiquetas de una imagen (borra las existentes para esa imagen y inserta las nuevas).
     */
    public function upsertForImage(string $workspaceSlug, string $relativePath, array $labels, string $modelVersion): void
    {
        $driver = \App\Services\AppConnection::getCurrentDriver();
        $now = date('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            if ($driver === 'mysql') {
                $del = $this->pdo->prepare("DELETE FROM {$this->tLabels} WHERE workspace_slug = ? AND relative_path = ?");
            } else {
                $del = $this->pdo->prepare("DELETE FROM {$this->tLabels} WHERE workspace_slug = ? AND relative_path = ?");
            }
            $del->execute([$workspaceSlug, $relativePath]);

            $stmt = $this->pdo->prepare("
                INSERT INTO {$this->tLabels} (workspace_slug, relative_path, taxonomy_level, label_name, parent_name, confidence, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($labels as $label) {
                $stmt->execute([
                    $workspaceSlug,
                    $relativePath,
                    (int) ($label['taxonomy_level'] ?? 0),
                    (string) ($label['label_name'] ?? ''),
                    isset($label['parent_name']) ? (string) $label['parent_name'] : null,
                    isset($label['confidence']) ? (float) $label['confidence'] : null,
                    $now,
                ]);
            }

            $upd = $this->pdo->prepare("
                UPDATE {$this->tImages} SET moderation_analyzed_at = ?, moderation_model_version = ?, updated_at = ?
                WHERE workspace_slug = ? AND relative_path = ?
            ");
            $upd->execute([$now, $modelVersion, $now, $workspaceSlug, $relativePath]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Obtiene las etiquetas de moderación para una imagen.
     * @return array<array{level:int, name:string, confidence:float|null}>
     */
    public function getForImage(string $workspaceSlug, string $relativePath): array
    {
        $stmt = $this->pdo->prepare("
            SELECT taxonomy_level, label_name, confidence
            FROM {$this->tLabels}
            WHERE workspace_slug = ? AND relative_path = ?
            ORDER BY taxonomy_level, label_name
        ");
        $stmt->execute([$workspaceSlug, $relativePath]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'level' => (int) ($r['taxonomy_level'] ?? 0),
                'name' => (string) ($r['label_name'] ?? ''),
                'confidence' => isset($r['confidence']) ? (float) $r['confidence'] : null,
            ];
        }
        return $out;
    }

    /**
     * Lista todas las etiquetas distintas encontradas en el workspace (para el buscador clicable).
     * Incluye "safe" (nivel 0). Devuelve label_name, taxonomy_level, example_confidence y count (registros con esa etiqueta).
     * @return array<array{label_name:string, taxonomy_level:int, example_confidence:float|null, count:int}>
     */
    public function getDistinctLabelsForWorkspace(string $workspaceSlug): array
    {
        $sql = "SELECT label_name, taxonomy_level, MAX(confidence) AS example_confidence,
                COUNT(DISTINCT relative_path) AS cnt
                FROM {$this->tLabels}
                WHERE workspace_slug = ?
                GROUP BY label_name, taxonomy_level
                ORDER BY taxonomy_level, label_name";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$workspaceSlug]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'label_name' => (string) ($r['label_name'] ?? ''),
                'taxonomy_level' => (int) ($r['taxonomy_level'] ?? 0),
                'example_confidence' => isset($r['example_confidence']) ? (float) $r['example_confidence'] : null,
                'count' => (int) ($r['cnt'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Cuenta imágenes que coinciden con nivel y/o etiquetas en un workspace.
     */
    public function getCountByLabels(string $workspaceSlug, ?int $taxonomyLevel, array $labelNames): int
    {
        if (empty($labelNames) && $taxonomyLevel === null) {
            return 0;
        }
        $params = [':ws' => $workspaceSlug];
        $conditions = ['l.workspace_slug = :ws', 'i.workspace_slug = l.workspace_slug', 'i.relative_path = l.relative_path'];
        if ($taxonomyLevel !== null) {
            $conditions[] = 'l.taxonomy_level = :level';
            $params[':level'] = $taxonomyLevel;
        }
        if (!empty($labelNames)) {
            $placeholders = [];
            foreach (array_values($labelNames) as $idx => $name) {
                $key = ':tag' . $idx;
                $placeholders[] = $key;
                $params[$key] = $name;
            }
            $conditions[] = 'l.label_name IN (' . implode(',', $placeholders) . ')';
        }
        $where = implode(' AND ', $conditions);
        $sql = "SELECT COUNT(DISTINCT i.relative_path) FROM {$this->tImages} i
            INNER JOIN {$this->tLabels} l ON i.workspace_slug = l.workspace_slug AND i.relative_path = l.relative_path
            WHERE " . $where;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Busca imágenes por nivel y/o etiquetas. Orden por label_name y confidence DESC. Soporta paginación.
     * @param int $offset Desplazamiento para paginación (0 = primera página).
     */
    public function findImagesByLabels(string $workspaceSlug, ?int $taxonomyLevel, array $labelNames, int $limit = 500, int $offset = 0): array
    {
        if (empty($labelNames) && $taxonomyLevel === null) {
            return [];
        }
        $params = [':ws' => $workspaceSlug];
        $conditions = ['l.workspace_slug = :ws', 'i.workspace_slug = l.workspace_slug', 'i.relative_path = l.relative_path'];
        if ($taxonomyLevel !== null) {
            $conditions[] = 'l.taxonomy_level = :level';
            $params[':level'] = $taxonomyLevel;
        }
        if (!empty($labelNames)) {
            $placeholders = [];
            foreach (array_values($labelNames) as $idx => $name) {
                $key = ':tag' . $idx;
                $placeholders[] = $key;
                $params[$key] = $name;
            }
            $conditions[] = 'l.label_name IN (' . implode(',', $placeholders) . ')';
        }
        $where = implode(' AND ', $conditions);
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $sql = "
            SELECT i.relative_path, i.full_path, i.folder_path, i.filename
            FROM {$this->tImages} i
            INNER JOIN {$this->tLabels} l ON i.workspace_slug = l.workspace_slug AND i.relative_path = l.relative_path
            WHERE " . $where . "
            GROUP BY i.relative_path, i.full_path, i.folder_path, i.filename
            ORDER BY MAX(l.confidence) DESC, MIN(l.label_name)
            LIMIT " . $limit . " OFFSET " . $offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $rel = (string) ($r['relative_path'] ?? '');
            $folderPath = (string) ($r['folder_path'] ?? '');
            $filename = (string) ($r['filename'] ?? '');
            if ($folderPath === '' || $filename === '') {
                $lastSlash = strrpos($rel, '/');
                if ($lastSlash !== false) {
                    if ($folderPath === '') $folderPath = substr($rel, 0, $lastSlash);
                    if ($filename === '') $filename = substr($rel, $lastSlash + 1);
                } else {
                    if ($filename === '') $filename = $rel;
                }
            }
            $out[] = [
                'relative_path' => $rel,
                'full_path' => isset($r['full_path']) ? (string) $r['full_path'] : null,
                'folder_path' => $folderPath,
                'filename' => $filename,
                'moderation_labels' => $this->getForImage($workspaceSlug, $rel),
            ];
        }
        return $out;
    }

    /**
     * Lista todas las etiquetas distintas encontradas en TODOS los workspaces (para buscador global).
     * Devuelve label_name, taxonomy_level y count total (suma de imágenes con esa etiqueta en todos los workspaces).
     * @return array<array{label_name:string, taxonomy_level:int, count:int}>
     */
    public function getDistinctLabelsGlobal(): array
    {
        $driver = \App\Services\AppConnection::getCurrentDriver();
        $distinctExpr = $driver === 'mysql'
            ? "COUNT(DISTINCT CONCAT(workspace_slug, ':', relative_path))"
            : "COUNT(DISTINCT workspace_slug || ':' || relative_path)";
        $sql = "SELECT label_name, taxonomy_level, " . $distinctExpr . " AS cnt
                FROM {$this->tLabels}
                GROUP BY label_name, taxonomy_level
                ORDER BY taxonomy_level, label_name";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'label_name' => (string) ($r['label_name'] ?? ''),
                'taxonomy_level' => (int) ($r['taxonomy_level'] ?? 0),
                'count' => (int) ($r['cnt'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Cuenta imágenes que coinciden con nivel y/o etiquetas en todos los workspaces.
     */
    public function getCountByLabelsGlobal(?int $taxonomyLevel, array $labelNames): int
    {
        if (empty($labelNames) && $taxonomyLevel === null) {
            return 0;
        }
        $params = [];
        $conditions = ['i.workspace_slug = l.workspace_slug', 'i.relative_path = l.relative_path'];
        if ($taxonomyLevel !== null) {
            $conditions[] = 'l.taxonomy_level = :level';
            $params[':level'] = $taxonomyLevel;
        }
        if (!empty($labelNames)) {
            $placeholders = [];
            foreach (array_values($labelNames) as $idx => $name) {
                $key = ':tag' . $idx;
                $placeholders[] = $key;
                $params[$key] = $name;
            }
            $conditions[] = 'l.label_name IN (' . implode(',', $placeholders) . ')';
        }
        $where = implode(' AND ', $conditions);
        $sql = "SELECT COUNT(DISTINCT i.workspace_slug || ':' || i.relative_path) FROM {$this->tImages} i
            INNER JOIN {$this->tLabels} l ON i.workspace_slug = l.workspace_slug AND i.relative_path = l.relative_path
            WHERE " . $where;
        if (\App\Services\AppConnection::getCurrentDriver() === 'mysql') {
            $sql = "SELECT COUNT(DISTINCT CONCAT(i.workspace_slug, ':', i.relative_path)) FROM {$this->tImages} i
                INNER JOIN {$this->tLabels} l ON i.workspace_slug = l.workspace_slug AND i.relative_path = l.relative_path
                WHERE " . $where;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Busca imágenes por nivel y/o etiquetas en TODOS los workspaces. Soporta paginación.
     * @param int $offset Desplazamiento para paginación (0 = primera página).
     */
    public function findImagesByLabelsGlobal(?int $taxonomyLevel, array $labelNames, int $limit = 500, int $offset = 0): array
    {
        if (empty($labelNames) && $taxonomyLevel === null) {
            return [];
        }
        $params = [];
        $conditions = ['i.workspace_slug = l.workspace_slug', 'i.relative_path = l.relative_path'];
        if ($taxonomyLevel !== null) {
            $conditions[] = 'l.taxonomy_level = :level';
            $params[':level'] = $taxonomyLevel;
        }
        if (!empty($labelNames)) {
            $placeholders = [];
            foreach (array_values($labelNames) as $idx => $name) {
                $key = ':tag' . $idx;
                $placeholders[] = $key;
                $params[$key] = $name;
            }
            $conditions[] = 'l.label_name IN (' . implode(',', $placeholders) . ')';
        }
        $where = implode(' AND ', $conditions);
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $sql = "
            SELECT i.workspace_slug, i.relative_path, i.full_path, i.folder_path, i.filename
            FROM {$this->tImages} i
            INNER JOIN {$this->tLabels} l ON i.workspace_slug = l.workspace_slug AND i.relative_path = l.relative_path
            WHERE " . $where . "
            GROUP BY i.workspace_slug, i.relative_path, i.full_path, i.folder_path, i.filename
            ORDER BY MAX(l.confidence) DESC, MIN(l.label_name)
            LIMIT " . $limit . " OFFSET " . $offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $ws = (string) ($r['workspace_slug'] ?? '');
            $rel = (string) ($r['relative_path'] ?? '');
            $folderPath = (string) ($r['folder_path'] ?? '');
            $filename = (string) ($r['filename'] ?? '');
            if ($folderPath === '' || $filename === '') {
                $lastSlash = strrpos($rel, '/');
                if ($lastSlash !== false) {
                    if ($folderPath === '') $folderPath = substr($rel, 0, $lastSlash);
                    if ($filename === '') $filename = substr($rel, $lastSlash + 1);
                } else {
                    if ($filename === '') $filename = $rel;
                }
            }
            $out[] = [
                'workspace_slug' => $ws,
                'relative_path' => $rel,
                'full_path' => isset($r['full_path']) ? (string) $r['full_path'] : null,
                'folder_path' => $folderPath,
                'filename' => $filename,
                'moderation_labels' => $this->getForImage($ws, $rel),
            ];
        }
        return $out;
    }
}
