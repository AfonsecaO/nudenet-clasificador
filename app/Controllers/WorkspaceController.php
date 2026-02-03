<?php

namespace App\Controllers;

use App\Services\WorkspaceService;
use PDO;

class WorkspaceController extends BaseController
{
    public function index()
    {
        $slugs = WorkspaceService::listWorkspaces();
        $current = WorkspaceService::current();

        $workspaces = [];
        foreach ($slugs as $slug) {
            $workspaces[] = $this->workspaceMeta((string)$slug, $current);
        }
        $this->render('workspace', [
            'workspaces' => $workspaces,
            'current' => $current,
        ]);
    }

    public function create()
    {
        $p = $this->payload();
        $name = isset($p['name']) ? (string)$p['name'] : '';
        $slug = WorkspaceService::slugify($name);
        if ($slug === '' || !WorkspaceService::isValidSlug($slug)) {
            $this->jsonResponse(['success' => false, 'error' => 'Nombre de workspace inválido'], 400);
        }

        if (!WorkspaceService::ensureStructure($slug)) {
            $this->jsonResponse(['success' => false, 'error' => 'No se pudo crear la estructura del workspace'], 500);
        }

        WorkspaceService::setCurrent($slug);
        $this->jsonResponse(['success' => true, 'workspace' => $slug]);
    }

    public function set()
    {
        $p = $this->payload();
        $slug = isset($p['workspace']) ? (string)$p['workspace'] : '';
        $slug = WorkspaceService::slugify($slug);
        if ($slug === '' || !WorkspaceService::isValidSlug($slug) || !WorkspaceService::exists($slug)) {
            $this->jsonResponse(['success' => false, 'error' => 'Workspace no existe'], 400);
        }
        WorkspaceService::setCurrent($slug);
        $this->jsonResponse(['success' => true, 'workspace' => $slug]);
    }

    public function delete()
    {
        $p = $this->payload();
        $slug = isset($p['workspace']) ? (string)$p['workspace'] : '';
        $confirm = isset($p['confirm']) ? (string)$p['confirm'] : '';

        $slug = WorkspaceService::slugify($slug);
        $confirm = WorkspaceService::slugify($confirm);

        if ($slug === '' || !WorkspaceService::isValidSlug($slug) || !WorkspaceService::exists($slug)) {
            $this->jsonResponse(['success' => false, 'error' => 'Workspace no existe'], 400);
        }
        if ($confirm !== $slug) {
            $this->jsonResponse(['success' => false, 'error' => 'Confirmación inválida. Debes escribir exactamente el nombre del workspace.'], 400);
        }

        $current = WorkspaceService::current();
        $ok = WorkspaceService::deleteWorkspace($slug);
        if (!$ok) {
            $this->jsonResponse(['success' => false, 'error' => 'No se pudo eliminar el workspace'], 500);
        }

        if ($current !== null && $current === $slug) {
            WorkspaceService::clearCurrent();
        }

        $this->jsonResponse(['success' => true, 'workspace' => $slug]);
    }

    private function payload(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $json = json_decode($raw ?: '', true);
            return is_array($json) ? $json : [];
        }
        if (!empty($_POST)) return $_POST;
        return $_GET ?? [];
    }

    private function workspaceMeta(string $slug, ?string $current): array
    {
        $slug = WorkspaceService::slugify($slug);
        $paths = WorkspaceService::paths($slug);
        $root = WorkspaceService::root($slug);

        $dbPath = $paths['dbPath'] ?? '';
        $imagesDir = $paths['imagesDir'] ?? '';

        $out = [
            'slug' => $slug,
            'is_current' => ($current !== null && $slug === $current),
            'root' => $root,
            'images_dir' => $imagesDir,
            'db_path' => $dbPath,
            'db_exists' => ($dbPath && is_file($dbPath)),
            'created_at' => is_dir($root) ? @filectime($root) : null,
            'updated_at' => null,
            'mode' => null,
            'configured' => false,
            'images_total' => null,
            'images_pending' => null,
            'detections_total' => null,
        ];

        if (!$out['db_exists']) {
            return $out;
        }

        try {
            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

            $has = function (string $table) use ($pdo): bool {
                $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:t LIMIT 1");
                $st->execute([':t' => $table]);
                return (bool)$st->fetchColumn();
            };

            if ($has('app_config')) {
                $cfg = [];
                $st = $pdo->query("SELECT key, value, updated_at FROM app_config");
                foreach (($st ? ($st->fetchAll() ?: []) : []) as $r) {
                    $k = isset($r['key']) ? (string)$r['key'] : '';
                    if ($k === '') continue;
                    $cfg[$k] = [
                        'value' => isset($r['value']) ? (string)$r['value'] : '',
                        'updated_at' => isset($r['updated_at']) ? (string)$r['updated_at'] : '',
                    ];
                }

                $mode = strtolower(trim((string)($cfg['WORKSPACE_MODE']['value'] ?? '')));
                if ($mode === 'db_and_images' || $mode === 'db') $out['mode'] = 'db_and_images';
                elseif ($mode !== '') $out['mode'] = 'images_only';

                $clas = trim((string)($cfg['CLASIFICADOR_BASE_URL']['value'] ?? ''));
                $out['configured'] = ($out['mode'] !== null) && ($clas !== '');

                // updated_at: última actualización de app_config
                $latest = null;
                foreach ($cfg as $row) {
                    $u = trim((string)($row['updated_at'] ?? ''));
                    if ($u === '') continue;
                    if ($latest === null || strcmp($u, $latest) > 0) $latest = $u;
                }
                $out['updated_at'] = $latest;
            }

            if ($has('images')) {
                $out['images_total'] = (int)$pdo->query("SELECT COUNT(*) FROM images")->fetchColumn();
                $out['images_pending'] = (int)$pdo->query("SELECT COUNT(*) FROM images WHERE detect_estado IS NULL OR detect_estado='na' OR detect_estado='pending'")->fetchColumn();
            }
            if ($has('detections')) {
                $out['detections_total'] = (int)$pdo->query("SELECT COUNT(*) FROM detections")->fetchColumn();
            }
        } catch (\Throwable $e) {
            // Silencioso: la vista solo muestra metadata best-effort
        }

        return $out;
    }
}

