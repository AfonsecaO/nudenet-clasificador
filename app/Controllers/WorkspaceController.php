<?php

namespace App\Controllers;

use App\Services\AppConnection;
use App\Services\ConfigService;
use App\Services\MysqlAppConfig;
use App\Services\StringNormalizer;
use App\Services\TablasLiveService;
use App\Services\WorkspaceService;
use PDO;

class WorkspaceController extends BaseController
{
    public function index()
    {
        $slugs = WorkspaceService::listWorkspaces();
        $current = WorkspaceService::current();

        // Actualizar max_id solo al cargar (workspaces globales); no en medio del proceso
        if ($current !== null && ConfigService::getWorkspaceMode() === 'db_and_images') {
            try {
                TablasLiveService::refrescar();
            } catch (\Throwable $e) {
                // Silencioso: si la BD externa falla, mostrar lista con estado previo
            }
        }

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
        $copyConfigFrom = isset($p['copy_config_from']) ? WorkspaceService::slugify((string)$p['copy_config_from']) : '';
        $slug = WorkspaceService::slugify($name);
        if ($slug === '' || !WorkspaceService::isValidSlug($slug)) {
            $this->jsonResponse(['success' => false, 'error' => 'Nombre de workspace inválido'], 400);
        }

        if (!WorkspaceService::ensureStructure($slug)) {
            $this->jsonResponse(['success' => false, 'error' => 'No se pudo crear la estructura del workspace'], 500);
        }

        if ($copyConfigFrom !== '' && $copyConfigFrom !== $slug && WorkspaceService::exists($copyConfigFrom)) {
            $this->copiarConfiguracionDesde($copyConfigFrom, $slug);
        }

        WorkspaceService::setCurrent($slug);
        $this->jsonResponse(['success' => true, 'workspace' => $slug]);
    }

    /**
     * Copia la parametrización del workspace origen al destino.
     * Normaliza claves a mayúsculas para evitar duplicados por capitalización.
     */
    private function copiarConfiguracionDesde(string $origenSlug, string $destinoSlug): void
    {
        $pdo = AppConnection::get();
        $t = AppConnection::table('app_config');
        $driver = AppConnection::getCurrentDriver();

        $sel = $pdo->prepare($driver === 'mysql'
            ? "SELECT `key`, value, updated_at FROM {$t} WHERE workspace_slug = :orig"
            : "SELECT key, value, updated_at FROM {$t} WHERE workspace_slug = :orig");
        $sel->execute([':orig' => $origenSlug]);
        $rows = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($driver === 'mysql') {
            $upsert = $pdo->prepare("
                INSERT INTO {$t} (workspace_slug, `key`, value, updated_at) VALUES (:ws, :k, :v, :t)
                ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)
            ");
        } else {
            $upsert = $pdo->prepare("
                INSERT INTO {$t} (workspace_slug, key, value, updated_at) VALUES (:ws, :k, :v, :t)
                ON CONFLICT(workspace_slug, key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at
            ");
        }

        foreach ($rows as $r) {
            $key = isset($r['key']) ? (string)$r['key'] : '';
            $kCanonical = ConfigService::normalizarKey($key);
            if ($kCanonical === '') continue;
            $upsert->execute([
                ':ws' => $destinoSlug,
                ':k' => $kCanonical,
                ':v' => (string)($r['value'] ?? ''),
                ':t' => (string)($r['updated_at'] ?? date('Y-m-d H:i:s')),
            ]);
        }
        ConfigService::dedupeAppConfigForWorkspace($destinoSlug);
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

    /**
     * Vista de parametrización global: motor (SQLite/MySQL) y, si MySQL, host, puerto, usuario, contraseña, base de datos.
     */
    public function globalConfig()
    {
        $config = MysqlAppConfig::get();
        $storageEngine = \App\Services\StorageEngineConfig::getStorageEngine();
        $this->render('workspace_global_config', [
            'mysql' => $config,
            'storage_engine' => $storageEngine,
        ]);
    }

    /**
     * Guardar parametrización global (API).
     * Acepta driver: 'sqlite' | 'mysql'. Si es mysql, valida conexión y guarda host, puerto, usuario, contraseña, base de datos.
     */
    public function globalConfigSave()
    {
        $p = $this->payload();
        $driver = isset($p['driver']) ? strtolower(trim((string) $p['driver'])) : '';

        if ($driver === 'sqlite') {
            \App\Services\StorageEngineConfig::setStorageEngine('sqlite');
            $this->jsonResponse(['success' => true]);
            return;
        }

        // driver === 'mysql' o no enviado: validar y guardar MySQL

        $host = isset($p['host']) ? trim((string) $p['host']) : '';
        $port = isset($p['port']) ? (int) $p['port'] : 3306;
        $user = isset($p['user']) ? trim((string) $p['user']) : '';
        $password = isset($p['password']) ? (string) $p['password'] : '';
        $database = isset($p['database']) ? trim((string) $p['database']) : '';

        if ($host === '') {
            $this->jsonResponse(['success' => false, 'error' => 'Host es obligatorio'], 400);
        }
        if ($port < 1 || $port > 65535) {
            $this->jsonResponse(['success' => false, 'error' => 'Puerto inválido'], 400);
        }
        if ($database === '') {
            $this->jsonResponse(['success' => false, 'error' => 'Base de datos es obligatoria'], 400);
        }

        if ($password === '') {
            $current = MysqlAppConfig::get();
            $password = (string) ($current['password'] ?? '');
        }

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->jsonResponse([
                'success' => false,
                'error' => 'Error de conexión: ' . $e->getMessage(),
            ], 400);
        }

        $toSave = [
            'host' => $host,
            'port' => $port,
            'user' => $user,
            'database' => $database,
            'connection_tested_ok' => true,
        ];
        if ((string) ($p['password'] ?? '') !== '') {
            $toSave['password'] = (string) $p['password'];
        }
        MysqlAppConfig::save($toSave);
        \App\Services\StorageEngineConfig::setStorageEngine('mysql');
        $this->jsonResponse(['success' => true]);
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
        $imagesDir = $paths['imagesDir'] ?? '';

        $pdo = AppConnection::get();
        $tAppConfig = AppConnection::table('app_config');
        $tImages = AppConnection::table('images');
        $tDetections = AppConnection::table('detections');

        $driver = AppConnection::getCurrentDriver();
        $storageLabel = $driver === 'mysql' ? 'MariaDB/MySQL' : 'SQLite';

        $out = [
            'slug' => $slug,
            'is_current' => ($current !== null && $slug === $current),
            'root' => $root,
            'images_dir' => $imagesDir,
            'db_path' => $storageLabel,
            'db_exists' => true,
            'created_at' => is_dir($root) ? @filectime($root) : null,
            'updated_at' => null,
            'mode' => null,
            'configured' => false,
            'images_total' => null,
            'images_pending' => null,
            'detections_total' => null,
            'registros_pendientes_descarga' => null,
        ];

        try {
            $st = $pdo->prepare("SELECT `key`, value, updated_at FROM {$tAppConfig} WHERE workspace_slug = :ws");
            $st->execute([':ws' => $slug]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!empty($rows)) {
                $cfg = [];
                foreach ($rows as $r) {
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

                $latest = null;
                foreach ($cfg as $row) {
                    $u = trim((string)($row['updated_at'] ?? ''));
                    if ($u === '') continue;
                    if ($latest === null || strcmp($u, $latest) > 0) $latest = $u;
                }
                $out['updated_at'] = $latest;
            }

            $st = $pdo->prepare("
                SELECT COUNT(*) AS total,
                    SUM(CASE WHEN detect_estado IS NULL OR detect_estado IN ('na','pending') THEN 1 ELSE 0 END) AS pending
                FROM {$tImages} WHERE workspace_slug = :ws
            ");
            $st->execute([':ws' => $slug]);
            $imgRow = $st->fetch(PDO::FETCH_ASSOC);
            $out['images_total'] = (int)($imgRow['total'] ?? 0);
            $out['images_pending'] = (int)($imgRow['pending'] ?? 0);

            $st = $pdo->prepare("SELECT COUNT(DISTINCT image_ruta_relativa) FROM {$tDetections} WHERE workspace_slug = :ws");
            $st->execute([':ws' => $slug]);
            $out['detections_total'] = (int)$st->fetchColumn();

            if ($out['mode'] === 'db_and_images') {
                $tTablesState = AppConnection::table('tables_state');
                $st = $pdo->prepare("SELECT ultimo_id, max_id FROM {$tTablesState} WHERE workspace_slug = :ws");
                $st->execute([':ws' => $slug]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $sumPendientes = 0;
                foreach ($rows as $r) {
                    $maxId = (int)($r['max_id'] ?? 0);
                    $ultimoId = (int)($r['ultimo_id'] ?? 0);
                    $sumPendientes += max(0, $maxId - $ultimoId + 1);
                }
                $out['registros_pendientes_descarga'] = $sumPendientes;
            }
        } catch (\Throwable $e) {
            // Silencioso: la vista solo muestra metadata best-effort
        }

        return $out;
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
            $pdo = AppConnection::get();
            $tFolders = AppConnection::table('folders');
            $tImages = AppConnection::table('images');
            $tDet = AppConnection::table('detections');

            foreach ($slugs as $slug) {
                $slug = (string)$slug;
                try {
                    if ($searchKey === '') {
                        $stmt = $pdo->prepare('SELECT ruta_carpeta as ruta, nombre, total_imagenes FROM ' . $tFolders . ' WHERE workspace_slug = :ws ORDER BY total_imagenes DESC, ruta_carpeta ASC LIMIT ' . self::LIMIT_CARPETAS_POR_WS);
                        $stmt->execute([':ws' => $slug]);
                        $rows = $stmt->fetchAll();
                    } else {
                        $stmt = $pdo->prepare("
                            SELECT ruta_carpeta as ruta, nombre, total_imagenes
                            FROM {$tFolders}
                            WHERE workspace_slug = :ws AND COALESCE(search_key, '') LIKE :q
                            ORDER BY total_imagenes DESC, ruta_carpeta ASC
                            LIMIT :lim
                        ");
                        $stmt->bindValue(':ws', $slug, PDO::PARAM_STR);
                        $stmt->bindValue(':q', '%' . $searchKey . '%', PDO::PARAM_STR);
                        $stmt->bindValue(':lim', self::LIMIT_CARPETAS_POR_WS, PDO::PARAM_INT);
                        $stmt->execute();
                        $rows = $stmt->fetchAll() ?: [];
                    }
                    $rutas = array_column($rows, 'ruta');
                    $rutas = array_values(array_unique(array_filter($rutas, fn($v) => $v !== '')));
                    $pendByRuta = [];
                    $tagsByRuta = [];
                    if ($rows && !empty($rutas)) {
                        $ph = implode(',', array_fill(0, count($rutas), '?'));
                        $stmtPend = $pdo->prepare("
                            SELECT i.ruta_carpeta as ruta, COUNT(*) as c
                            FROM {$tImages} i
                            WHERE i.workspace_slug = ? AND i.ruta_carpeta IN ($ph)
                              AND (COALESCE(i.detect_estado,'') <> 'ok' OR COALESCE(i.clasif_estado,'') = 'pending')
                            GROUP BY i.ruta_carpeta
                        ");
                        $stmtPend->execute(array_merge([$slug], $rutas));
                        foreach ($stmtPend->fetchAll() ?: [] as $row) {
                            $rutaP = (string)($row['ruta'] ?? '');
                            if ($rutaP !== '') $pendByRuta[$rutaP] = (int)($row['c'] ?? 0);
                        }
                        // Tags sin JOIN: imágenes por carpeta, luego detecciones por imagen
                        $stmtImg = $pdo->prepare("
                            SELECT ruta_relativa, ruta_carpeta
                            FROM {$tImages}
                            WHERE workspace_slug = ? AND COALESCE(ruta_carpeta,'') IN ($ph)
                        ");
                        $stmtImg->execute(array_merge([$slug], $rutas));
                        $imgRows = $stmtImg->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        $rutaToCarpeta = [];
                        $rutaRelativas = [];
                        foreach ($imgRows as $r) {
                            $rrel = (string)($r['ruta_relativa'] ?? '');
                            $rc = (string)($r['ruta_carpeta'] ?? '');
                            if ($rrel !== '') {
                                $rutaToCarpeta[$rrel] = $rc;
                                $rutaRelativas[] = $rrel;
                            }
                        }
                        if (!empty($rutaRelativas)) {
                            $ph2 = implode(',', array_fill(0, count($rutaRelativas), '?'));
                            $stmtDet = $pdo->prepare("
                                SELECT image_ruta_relativa, label
                                FROM {$tDet}
                                WHERE workspace_slug = ? AND image_ruta_relativa IN ($ph2)
                            ");
                            $stmtDet->execute(array_merge([$slug], $rutaRelativas));
                            $byRutaLabel = [];
                            foreach ($stmtDet->fetchAll(PDO::FETCH_ASSOC) ?: [] as $dr) {
                                $rrel = (string)($dr['image_ruta_relativa'] ?? '');
                                $lab = isset($dr['label']) ? strtoupper(trim((string)$dr['label'])) : '';
                                if ($rrel === '' || $lab === '') continue;
                                $rc = $rutaToCarpeta[$rrel] ?? null;
                                if ($rc === null) continue;
                                $key = $rc . "\0" . $lab;
                                if (!isset($byRutaLabel[$key])) $byRutaLabel[$key] = [];
                                $byRutaLabel[$key][$rrel] = true;
                            }
                            foreach ($byRutaLabel as $key => $set) {
                                $parts = explode("\0", $key, 2);
                                $rc = $parts[0] ?? '';
                                $lab = $parts[1] ?? '';
                                if ($rc === '' || $lab === '') continue;
                                if (!isset($tagsByRuta[$rc])) $tagsByRuta[$rc] = [];
                                $tagsByRuta[$rc][$lab] = (int)count($set);
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
            $pdo = AppConnection::get();
            $tDet = AppConnection::table('detections');

            foreach ($slugs as $slug) {
                $slug = (string)$slug;
                try {
                    $stmt = $pdo->prepare("
                        SELECT label,
                               COUNT(DISTINCT image_ruta_relativa) as c,
                               MIN(score) as min_score,
                               MAX(score) as max_score
                        FROM {$tDet}
                        WHERE workspace_slug = :ws
                        GROUP BY label
                        ORDER BY c DESC, label ASC
                    ");
                    $stmt->execute([':ws' => $slug]);
                    $rows = $stmt->fetchAll();
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

    /**
     * Búsqueda global: detections donde label = tag seleccionado y score >= umbral.
     * Una sola consulta con JOIN a images para traer los datos necesarios.
     */
    public function buscarImagenesEtiquetasConsolidado()
    {
        try {
            $labelsStr = $_GET['labels'] ?? '';
            $umbral = isset($_GET['umbral']) ? (int)$_GET['umbral'] : 0;
            $umbral = max(0, min(100, $umbral));
            $umbralFloat = $umbral / 100.0;

            $labelsStr = trim((string)$labelsStr);
            $labels = $labelsStr !== '' ? array_values(array_filter(array_map('trim', explode(',', $labelsStr)))) : [];
            if (empty($labels)) {
                $this->jsonResponse(['success' => false, 'error' => 'Parámetro labels requerido (csv)'], 400);
                return;
            }

            $labelsNorm = array_values(array_unique(array_filter(array_map(function ($lab) {
                return self::normalizarLabel((string)$lab);
            }, $labels))));
            if (empty($labelsNorm)) {
                $this->jsonResponse(['success' => true, 'labels' => $labels, 'umbral' => $umbral, 'total' => 0, 'imagenes' => []]);
                return;
            }

            \App\Services\AppSchema::ensure(AppConnection::get());
            $pdo = AppConnection::get();
            $tDet = AppConnection::table('detections');
            $tImg = AppConnection::table('images');

            // Resolver labels tal como están en la BD (para coincidir exacto)
            $labelsToUse = [];
            try {
                $stmtLabels = $pdo->query("SELECT DISTINCT label FROM {$tDet}");
                while ($stmtLabels && ($row = $stmtLabels->fetch(PDO::FETCH_NUM))) {
                    $raw = (string)($row[0] ?? '');
                    if ($raw !== '' && in_array(self::normalizarLabel($raw), $labelsNorm, true)) {
                        $labelsToUse[$raw] = true;
                    }
                }
                $labelsToUse = array_keys($labelsToUse);
            } catch (\Throwable $e) {
                $labelsToUse = [];
            }
            if (empty($labelsToUse)) {
                $this->jsonResponse(['success' => true, 'labels' => $labels, 'umbral' => $umbral, 'total' => 0, 'imagenes' => []]);
                return;
            }

            $ph = implode(',', array_fill(0, count($labelsToUse), '?'));
            $sql = "
                SELECT d.workspace_slug, d.image_ruta_relativa, MAX(d.score) AS best_score,
                       MAX(i.ruta_carpeta) AS ruta_carpeta, MAX(i.archivo) AS archivo
                FROM {$tDet} d
                INNER JOIN {$tImg} i ON i.workspace_slug = d.workspace_slug AND i.ruta_relativa = d.image_ruta_relativa
                WHERE d.label IN ({$ph}) AND d.score >= ?
                GROUP BY d.workspace_slug, d.image_ruta_relativa
                ORDER BY best_score DESC
                LIMIT " . self::LIMIT_IMAGENES_GLOBAL;
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($labelsToUse, [$umbralFloat]));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $imagenes = [];
            foreach ($rows as $r) {
                $imagenes[] = [
                    'workspace' => (string)($r['workspace_slug'] ?? ''),
                    'ruta_relativa' => (string)($r['image_ruta_relativa'] ?? ''),
                    'ruta_carpeta' => (string)($r['ruta_carpeta'] ?? ''),
                    'archivo' => (string)($r['archivo'] ?? ''),
                    'best_score' => (float)($r['best_score'] ?? 0),
                    'matches' => [],
                ];
            }

            $this->jsonResponse([
                'success' => true,
                'labels' => $labels,
                'umbral' => $umbral,
                'total' => count($imagenes),
                'imagenes' => $imagenes,
            ]);
        } catch (\Exception $e) {
            $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}

