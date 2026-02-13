<?php

namespace App\Controllers;

use App\Services\StringNormalizer;
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

    /**
     * Abre PDO para un workspace por path. Devuelve null si no hay DB o falla.
     */
    private function pdoForSlug(string $slug): ?PDO
    {
        $paths = WorkspaceService::paths($slug);
        $dbPath = $paths['dbPath'] ?? '';
        if ($dbPath === '' || !is_file($dbPath)) {
            return null;
        }
        try {
            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            return $pdo;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function hasTable(PDO $pdo, string $table): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:t LIMIT 1");
        $st->execute([':t' => $table]);
        return (bool)$st->fetchColumn();
    }

    private function nombreDesdeRuta(string $ruta): string
    {
        $ruta = str_replace('\\', '/', $ruta);
        $ruta = trim($ruta, '/');
        if ($ruta === '') return '';
        return basename($ruta);
    }

    private const LIMIT_CARPETAS_POR_WS = 100;
    private const LIMIT_IMAGENES_POR_WS = 5000;
    private const LIMIT_IMAGENES_GLOBAL = 10000;

    public function buscarCarpetasConsolidado()
    {
        try {
            $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
            $slugs = WorkspaceService::listWorkspaces();
            $carpetas = [];
            $searchKey = $q !== '' ? StringNormalizer::toSearchKey($q) : '';

            foreach ($slugs as $slug) {
                $pdo = $this->pdoForSlug((string)$slug);
                if ($pdo === null || !$this->hasTable($pdo, 'folders')) {
                    continue;
                }
                try {
                    if ($searchKey === '') {
                        $rows = $pdo->query('SELECT ruta_carpeta as ruta, nombre, total_imagenes FROM folders ORDER BY total_imagenes DESC, ruta_carpeta ASC LIMIT ' . self::LIMIT_CARPETAS_POR_WS)->fetchAll();
                    } else {
                        $stmt = $pdo->prepare("
                            SELECT ruta_carpeta as ruta, nombre, total_imagenes
                            FROM folders
                            WHERE COALESCE(search_key, '') LIKE :q
                            ORDER BY total_imagenes DESC, ruta_carpeta ASC
                            LIMIT :lim
                        ");
                        $stmt->bindValue(':q', '%' . $searchKey . '%', PDO::PARAM_STR);
                        $stmt->bindValue(':lim', self::LIMIT_CARPETAS_POR_WS, PDO::PARAM_INT);
                        $stmt->execute();
                        $rows = $stmt->fetchAll() ?: [];
                    }
                    $rutas = array_column($rows, 'ruta');
                    $rutas = array_values(array_unique(array_filter($rutas, fn($v) => $v !== '')));
                    $pendByRuta = [];
                    $tagsByRuta = [];
                    if ($rows && !empty($rutas) && $this->hasTable($pdo, 'images')) {
                        $ph = implode(',', array_fill(0, count($rutas), '?'));
                        $stmtPend = $pdo->prepare("
                            SELECT i.ruta_carpeta as ruta, COUNT(*) as c
                            FROM images i
                            WHERE i.ruta_carpeta IN ($ph)
                              AND (COALESCE(i.detect_estado,'') <> 'ok' OR COALESCE(i.clasif_estado,'') = 'pending')
                            GROUP BY i.ruta_carpeta
                        ");
                        $stmtPend->execute($rutas);
                        foreach ($stmtPend->fetchAll() ?: [] as $row) {
                            $rutaP = (string)($row['ruta'] ?? '');
                            if ($rutaP !== '') $pendByRuta[$rutaP] = (int)($row['c'] ?? 0);
                        }
                        if ($this->hasTable($pdo, 'detections')) {
                            $stmtTags = $pdo->prepare("
                                SELECT i.ruta_carpeta as ruta, d.label as label, COUNT(DISTINCT i.ruta_relativa) as c
                                FROM images i
                                JOIN detections d ON d.image_ruta_relativa = i.ruta_relativa
                                WHERE COALESCE(i.ruta_carpeta,'') IN ($ph)
                                GROUP BY i.ruta_carpeta, d.label
                            ");
                            $stmtTags->execute($rutas);
                            foreach ($stmtTags->fetchAll() ?: [] as $row) {
                                $rutaP = (string)($row['ruta'] ?? '');
                                $lab = isset($row['label']) ? strtoupper(trim((string)$row['label'])) : '';
                                $cnt = (int)($row['c'] ?? 0);
                                if ($rutaP !== '' && $lab !== '' && $cnt > 0) {
                                    if (!isset($tagsByRuta[$rutaP])) $tagsByRuta[$rutaP] = [];
                                    $tagsByRuta[$rutaP][$lab] = ($tagsByRuta[$rutaP][$lab] ?? 0) + $cnt;
                                }
                            }
                        }
                    }
                    $batch = [];
                    foreach ($rows as $r) {
                        $ruta = (string)($r['ruta'] ?? '');
                        $nombre = (string)($r['nombre'] ?? '');
                        if ($nombre === '' && $ruta !== '') {
                            $nombre = $this->nombreDesdeRuta($ruta);
                        }
                        $tagsMap = $tagsByRuta[$ruta] ?? [];
                        $tagsList = [];
                        foreach ($tagsMap as $lab => $cnt) {
                            $tagsList[] = ['label' => $lab, 'count' => (int)$cnt];
                        }
                        usort($tagsList, fn($a, $b) => ($b['count'] <=> $a['count']) ?: strcmp((string)($a['label'] ?? ''), (string)($b['label'] ?? '')));
                        $tagsList = array_slice($tagsList, 0, 12);
                        $batch[] = [
                            'nombre' => $nombre !== '' ? $nombre : $ruta,
                            'ruta' => $ruta,
                            'total_archivos' => (int)($r['total_imagenes'] ?? 0),
                            'pendientes' => (int)($pendByRuta[$ruta] ?? 0),
                            'workspace' => $slug,
                            'tags' => $tagsList,
                        ];
                    }
                    try {
                        $batch = (new CarpetasController())->enriquecerCarpetasConAvataresParaWorkspace((string)$slug, $batch, $pdo);
                    } catch (\Throwable $e) {
                        // mantener batch sin avatares
                    }
                    foreach ($batch as $item) {
                        $carpetas[] = $item;
                    }
                } catch (\Throwable $e) {
                    // omitir workspace
                }
            }

            usort($carpetas, fn($a, $b) => ($b['total_archivos'] <=> $a['total_archivos']) ?: strcmp((string)($a['ruta'] ?? ''), (string)($b['ruta'] ?? '')));

            $this->jsonResponse([
                'success' => true,
                'carpetas' => $carpetas,
                'total' => count($carpetas),
                'busqueda' => $q,
            ]);
        } catch (\Exception $e) {
            $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function etiquetasDetectadasConsolidado()
    {
        try {
            $slugs = WorkspaceService::listWorkspaces();
            $byLabel = [];

            foreach ($slugs as $slug) {
                $pdo = $this->pdoForSlug((string)$slug);
                if ($pdo === null || !$this->hasTable($pdo, 'detections')) {
                    continue;
                }
                try {
                    $rows = $pdo->query("
                        SELECT label,
                               COUNT(DISTINCT image_ruta_relativa) as c,
                               MIN(score) as min_score,
                               MAX(score) as max_score
                        FROM detections
                        GROUP BY label
                        ORDER BY c DESC, label ASC
                    ")->fetchAll();
                    foreach ($rows as $r) {
                        $label = (string)($r['label'] ?? '');
                        if ($label === '') continue;
                        $c = (int)($r['c'] ?? 0);
                        $minScore = isset($r['min_score']) ? (float)$r['min_score'] : 0;
                        $maxScore = isset($r['max_score']) ? (float)$r['max_score'] : 0;
                        if (!isset($byLabel[$label])) {
                            $byLabel[$label] = ['label' => $label, 'count' => 0, 'min' => 100, 'max' => 0];
                        }
                        $byLabel[$label]['count'] += $c;
                        $minPct = (int)round($minScore * 100);
                        $maxPct = (int)round($maxScore * 100);
                        if ($minPct < $byLabel[$label]['min']) $byLabel[$label]['min'] = $minPct;
                        if ($maxPct > $byLabel[$label]['max']) $byLabel[$label]['max'] = $maxPct;
                    }
                } catch (\Throwable $e) {
                    // omitir workspace
                }
            }

            $etiquetas = array_values($byLabel);
            usort($etiquetas, fn($a, $b) => ($b['max'] <=> $a['max']) ?: ($b['count'] <=> $a['count']) ?: strcmp($a['label'], $b['label']));

            $this->jsonResponse([
                'success' => true,
                'etiquetas' => $etiquetas,
                'total' => count($etiquetas),
            ]);
        } catch (\Exception $e) {
            $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private static function normalizarLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '') return '';
        $label = strtoupper($label);
        $label = preg_replace('/\s+/', '_', $label);
        return $label;
    }

    public function buscarImagenesEtiquetasConsolidado()
    {
        try {
            $labelsStr = $_GET['labels'] ?? '';
            $umbral = isset($_GET['umbral']) ? (int)$_GET['umbral'] : 0;
            $umbral = max(0, min(100, $umbral));
            $umbralFloat = $umbral / 100.0;

            $labelsStr = trim((string)$labelsStr);
            $labels = [];
            if ($labelsStr !== '') {
                $labels = array_filter(array_map('trim', explode(',', $labelsStr)));
            }

            if (empty($labels)) {
                $this->jsonResponse(['success' => false, 'error' => 'Parámetro labels requerido (csv)'], 400);
                return;
            }

            $labelsNorm = [];
            foreach ($labels as $lab) {
                $lab = self::normalizarLabel((string)$lab);
                if ($lab !== '') $labelsNorm[] = $lab;
            }
            $labelsNorm = array_values(array_unique($labelsNorm));
            if (empty($labelsNorm)) {
                $this->jsonResponse(['success' => true, 'labels' => $labels, 'umbral' => $umbral, 'total' => 0, 'imagenes' => []]);
                return;
            }

            $slugs = WorkspaceService::listWorkspaces();
            $allImagenes = [];
            $placeholders = implode(',', array_fill(0, count($labelsNorm), '?'));
            $labelCondition = "UPPER(REPLACE(TRIM(d.label), ' ', '_')) IN ($placeholders)";

            foreach ($slugs as $slug) {
                if (count($allImagenes) >= self::LIMIT_IMAGENES_GLOBAL) {
                    break;
                }
                $pdo = $this->pdoForSlug((string)$slug);
                if ($pdo === null || !$this->hasTable($pdo, 'detections') || !$this->hasTable($pdo, 'images')) {
                    continue;
                }
                try {
                    $stmtTop = $pdo->prepare("
                        SELECT d.image_ruta_relativa, MAX(d.score) as best_score
                        FROM detections d
                        WHERE $labelCondition AND d.score >= ?
                        GROUP BY d.image_ruta_relativa
                        ORDER BY best_score DESC
                        LIMIT " . self::LIMIT_IMAGENES_POR_WS . "
                    ");
                    $stmtTop->execute(array_merge($labelsNorm, [$umbralFloat]));
                    $top = $stmtTop->fetchAll();
                    if (empty($top)) continue;

                    $imageKeys = array_map(fn($r) => (string)$r['image_ruta_relativa'], $top);
                    $bestByKey = [];
                    foreach ($top as $r) $bestByKey[(string)$r['image_ruta_relativa']] = (float)$r['best_score'];

                    $ph2 = implode(',', array_fill(0, count($imageKeys), '?'));
                    $stmtExists = $pdo->prepare("SELECT ruta_relativa FROM images WHERE ruta_relativa IN ($ph2)");
                    $stmtExists->execute($imageKeys);
                    $existingKeys = [];
                    while ($row = $stmtExists->fetch(PDO::FETCH_NUM)) {
                        $existingKeys[(string)$row[0]] = true;
                    }
                    $imageKeys = array_values(array_filter($imageKeys, fn($k) => !empty($existingKeys[$k])));
                    if (empty($imageKeys)) continue;

                    $stmtInfo = $pdo->prepare("SELECT ruta_relativa, ruta_carpeta, archivo FROM images WHERE ruta_relativa IN ($ph2)");
                    $stmtInfo->execute($imageKeys);
                    $infoRows = $stmtInfo->fetchAll();
                    $infoByKey = [];
                    foreach ($infoRows as $r) $infoByKey[(string)$r['ruta_relativa']] = $r;

                    $stmtMatches = $pdo->prepare("
                        SELECT d.image_ruta_relativa, d.label, MAX(d.score) as score
                        FROM detections d
                        WHERE d.image_ruta_relativa IN ($ph2)
                          AND UPPER(REPLACE(TRIM(d.label), ' ', '_')) IN ($placeholders)
                          AND d.score >= ?
                        GROUP BY d.image_ruta_relativa, d.label
                    ");
                    $stmtMatches->execute(array_merge($imageKeys, $labelsNorm, [$umbralFloat]));
                    $matchRows = $stmtMatches->fetchAll();
                    $matchesByKey = [];
                    foreach ($matchRows as $r) {
                        $k = (string)$r['image_ruta_relativa'];
                        $matchesByKey[$k][] = ['label' => (string)$r['label'], 'score' => (float)$r['score']];
                    }

                    foreach ($imageKeys as $k) {
                        $info = $infoByKey[$k] ?? null;
                        if (!$info) continue;
                        $allImagenes[] = [
                            'ruta_relativa' => $k,
                            'ruta_carpeta' => (string)($info['ruta_carpeta'] ?? ''),
                            'archivo' => (string)($info['archivo'] ?? ''),
                            'best_score' => (float)($bestByKey[$k] ?? 0),
                            'matches' => $matchesByKey[$k] ?? [],
                            'workspace' => $slug,
                        ];
                    }
                } catch (\Throwable $e) {
                    // omitir workspace
                }
            }

            usort($allImagenes, fn($a, $b) => ($b['best_score'] <=> $a['best_score']));

            $this->jsonResponse([
                'success' => true,
                'labels' => $labels,
                'umbral' => $umbral,
                'total' => count($allImagenes),
                'imagenes' => $allImagenes,
            ]);
        } catch (\Exception $e) {
            $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}

