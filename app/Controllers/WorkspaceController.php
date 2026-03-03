<?php

namespace App\Controllers;

use App\Services\AppConnection;
use App\Services\ConfigService;
use App\Services\MysqlAppConfig;
use App\Services\StorageEngineConfig;
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
        $storageEngine = StorageEngineConfig::getStorageEngine();
        $aws = StorageEngineConfig::getAwsConfig();
        $this->render('workspace_global_config', [
            'mysql' => $config,
            'storage_engine' => $storageEngine,
            'registros_descarga' => StorageEngineConfig::getRegistrosDescarga(),
            'registros_moderacion' => StorageEngineConfig::getRegistrosModeracion(),
            'aws' => $aws,
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
            StorageEngineConfig::setStorageEngine('sqlite');
            $n = isset($p['registros_descarga']) ? (int) $p['registros_descarga'] : 1;
            $n = max(1, min(1000, $n));
            StorageEngineConfig::setRegistrosDescarga($n);
            $nMod = isset($p['registros_moderacion']) ? (int) $p['registros_moderacion'] : 20;
            $nMod = max(1, min(100, $nMod));
            StorageEngineConfig::setRegistrosModeracion($nMod);
            $this->persistAwsConfig($p);
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
        StorageEngineConfig::setStorageEngine('mysql');
        $n = isset($p['registros_descarga']) ? (int) $p['registros_descarga'] : 1;
        $n = max(1, min(1000, $n));
        StorageEngineConfig::setRegistrosDescarga($n);
        $nMod = isset($p['registros_moderacion']) ? (int) $p['registros_moderacion'] : 20;
        $nMod = max(1, min(100, $nMod));
        StorageEngineConfig::setRegistrosModeracion($nMod);
        $this->persistAwsConfig($p);
        $this->jsonResponse(['success' => true]);
    }

    /**
     * Persiste la sección AWS desde el payload (preserva secret si viene vacío).
     */
    private function persistAwsConfig(array $p): void
    {
        if (!isset($p['aws']) || !is_array($p['aws'])) {
            return;
        }
        $aws = $p['aws'];
        $current = StorageEngineConfig::getAwsConfig();
        if ((string) ($aws['secret'] ?? '') === '' && isset($current['secret'])) {
            $aws['secret'] = (string) $current['secret'];
        }
        $out = [
            'key' => isset($aws['key']) ? trim((string) $aws['key']) : '',
            'secret' => isset($aws['secret']) ? (string) $aws['secret'] : '',
            'region' => isset($aws['region']) ? trim((string) $aws['region']) : 'us-east-1',
            'version' => isset($aws['version']) ? trim((string) $aws['version']) : 'latest',
            'min_confidence' => isset($aws['min_confidence']) ? (float) $aws['min_confidence'] : 50.0,
        ];
        $out['min_confidence'] = max(0.0, min(100.0, $out['min_confidence']));
        StorageEngineConfig::setAwsConfig($out);
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
            'images_pending' => 0,
            'registros_pendientes_descarga' => null,
            'imagenes_pendientes_moderacion' => null,
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

                $out['configured'] = ($out['mode'] !== null);

                $latest = null;
                foreach ($cfg as $row) {
                    $u = trim((string)($row['updated_at'] ?? ''));
                    if ($u === '') continue;
                    if ($latest === null || strcmp($u, $latest) > 0) $latest = $u;
                }
                $out['updated_at'] = $latest;
            }

            $st = $pdo->prepare("SELECT COUNT(*) AS total FROM {$tImages} WHERE workspace_slug = :ws");
            $st->execute([':ws' => $slug]);
            $out['images_total'] = (int)$st->fetchColumn();

            try {
                $condMod = AppConnection::getCurrentDriver() === 'mysql'
                    ? 'moderation_analyzed_at IS NULL'
                    : '(moderation_analyzed_at IS NULL OR moderation_analyzed_at = \'\')';
                $stMod = $pdo->prepare("SELECT COUNT(*) FROM {$tImages} WHERE workspace_slug = :ws AND {$condMod}");
                $stMod->execute([':ws' => $slug]);
                $out['imagenes_pendientes_moderacion'] = (int)$stMod->fetchColumn();
            } catch (\Throwable $e) {
                // Si la columna no existe (migración pendiente) o hay error, todas las imágenes están por moderar
                $out['imagenes_pendientes_moderacion'] = (int)($out['images_total'] ?? 0);
            }

            if ($out['mode'] === 'db_and_images') {
                $tTablesState = AppConnection::table('tables_state');
                $repair = $pdo->prepare("
                    UPDATE {$tTablesState}
                    SET last_processed_id = max_id
                    WHERE workspace_slug = :ws AND (COALESCE(has_pending, 1) = 0) AND last_processed_id < max_id
                ");
                $repair->execute([':ws' => $slug]);
                $st = $pdo->prepare("SELECT last_processed_id, max_id FROM {$tTablesState} WHERE workspace_slug = :ws");
                $st->execute([':ws' => $slug]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $sumPendientes = 0;
                foreach ($rows as $r) {
                    $maxId = (int)($r['max_id'] ?? 0);
                    $ultimoId = (int)($r['last_processed_id'] ?? 0);
                    $sumPendientes += max(0, $maxId - $ultimoId);
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

            foreach ($slugs as $slug) {
                $slug = (string)$slug;
                try {
                    if ($searchKey === '') {
                        $stmt = $pdo->prepare('SELECT folder_path as ruta, name as nombre, image_count as total_imagenes FROM ' . $tFolders . ' WHERE workspace_slug = :ws ORDER BY image_count DESC, folder_path ASC LIMIT ' . self::LIMIT_CARPETAS_POR_WS);
                        $stmt->execute([':ws' => $slug]);
                        $rows = $stmt->fetchAll();
                    } else {
                        $stmt = $pdo->prepare("
                            SELECT folder_path as ruta, name as nombre, image_count as total_imagenes
                            FROM {$tFolders}
                            WHERE workspace_slug = :ws AND COALESCE(search_key, '') LIKE :q
                            ORDER BY image_count DESC, folder_path ASC
                            LIMIT :lim
                        ");
                        $stmt->bindValue(':ws', $slug, PDO::PARAM_STR);
                        $stmt->bindValue(':q', '%' . $searchKey . '%', PDO::PARAM_STR);
                        $stmt->bindValue(':lim', self::LIMIT_CARPETAS_POR_WS * 2, PDO::PARAM_INT);
                        $stmt->execute();
                        $rows = $stmt->fetchAll() ?: [];
                        $rutaNorm = trim(str_replace('\\', '/', $q), '/');
                        $singleSegment = ($rutaNorm !== '' && strpos($rutaNorm, '/') === false);
                        if ($singleSegment && count($rows) > 1) {
                            $exact = [];
                            $prefixMatch = [];
                            $prefix = $rutaNorm . '/';
                            foreach ($rows as $r) {
                                $rc = trim(str_replace('\\', '/', (string)($r['ruta'] ?? '')), '/');
                                if ($rc === $rutaNorm) {
                                    $exact[] = $r;
                                } elseif (strpos($rc, $prefix) === 0 || basename($rc) === $rutaNorm) {
                                    $prefixMatch[] = $r;
                                }
                            }
                            if (count($exact) > 0) {
                                $rows = $exact;
                            } elseif (count($prefixMatch) > 0) {
                                usort($prefixMatch, function ($a, $b) {
                                    $na = (int)($a['total_imagenes'] ?? 0);
                                    $nb = (int)($b['total_imagenes'] ?? 0);
                                    return $nb <=> $na;
                                });
                                $rows = [ $prefixMatch[0] ];
                            } else {
                                $rows = [ $rows[0] ];
                            }
                        }
                        $rows = array_slice($rows, 0, self::LIMIT_CARPETAS_POR_WS);
                    }
                    $rutas = array_column($rows, 'ruta');
                    $rutas = array_values(array_unique(array_filter($rutas, fn($v) => $v !== '')));
                    $totalByRuta = [];
                    if ($rows && !empty($rutas)) {
                        $ph = implode(',', array_fill(0, count($rutas), '?'));
                        $stmtTotal = $pdo->prepare("
                            SELECT i.folder_path as ruta, COUNT(*) as c
                            FROM {$tImages} i
                            WHERE i.workspace_slug = ? AND i.folder_path IN ($ph)
                            GROUP BY i.folder_path
                        ");
                        $stmtTotal->execute(array_merge([$slug], $rutas));
                        foreach ($stmtTotal->fetchAll() ?: [] as $row) {
                            $rutaP = (string)($row['ruta'] ?? '');
                            if ($rutaP !== '') $totalByRuta[$rutaP] = (int)($row['c'] ?? 0);
                        }
                    }
                    $batch = [];
                    foreach ($rows as $r) {
                        $ruta = (string)($r['ruta'] ?? '');
                        $nombre = (string)($r['nombre'] ?? '');
                        if ($nombre === '' && $ruta !== '') {
                            $nombre = $this->nombreDesdeRuta($ruta);
                        }
                        $totalReal = (int)($totalByRuta[$ruta] ?? $r['total_imagenes'] ?? 0);
                        $batch[] = [
                            'nombre' => $nombre !== '' ? $nombre : $ruta,
                            'ruta' => $ruta,
                            'total_archivos' => $totalReal,
                            'pendientes' => 0,
                            'workspace' => $slug,
                            'tags' => [],
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

            $rutaNorm = trim(str_replace('\\', '/', $q), '/');
            $singleSegment = ($rutaNorm !== '' && strpos($rutaNorm, '/') === false && strpos($q, ' ') === false);
            if ($singleSegment && count($carpetas) > 0) {
                $byWs = [];
                foreach ($carpetas as $c) {
                    $ws = (string)($c['workspace'] ?? '');
                    if (!isset($byWs[$ws])) {
                        $byWs[$ws] = [];
                    }
                    $byWs[$ws][] = $c;
                }
                $carpetas = [];
                foreach ($byWs as $ws => $list) {
                    $elegida = null;
                    foreach ($list as $c) {
                        $r = trim(str_replace('\\', '/', (string)($c['ruta'] ?? '')), '/');
                        if ($r === $rutaNorm) {
                            $elegida = $c;
                            break;
                        }
                    }
                    if ($elegida === null && count($list) > 0) {
                        usort($list, fn($a, $b) => ($b['total_archivos'] <=> $a['total_archivos']) ?: strcmp((string)($a['ruta'] ?? ''), (string)($b['ruta'] ?? '')));
                        $elegida = $list[0];
                    }
                    if ($elegida !== null) {
                        $carpetas[] = $elegida;
                    }
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

}

