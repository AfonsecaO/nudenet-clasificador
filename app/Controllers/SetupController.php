<?php

namespace App\Controllers;

use PDO;

class SetupController extends BaseController
{
    public function index()
    {
        $keys = array_merge(
            \App\Services\ConfigService::getKeysRequeridas(),
            \App\Services\ConfigService::getKeysOpcionales()
        );

        $values = [];
        foreach ($keys as $k) {
            $values[$k] = \App\Services\ConfigService::get($k);
        }

        // Workspace nuevo: valores por defecto solo si no hay nada en BD (prioridad a lo guardado)
        $urlStored = isset($values['CLASIFICADOR_BASE_URL']) ? trim((string)$values['CLASIFICADOR_BASE_URL']) : '';
        if ($urlStored === '') {
            $values['CLASIFICADOR_BASE_URL'] = 'http://localhost:8001/';
        }
        $ignoredStored = isset($values['DETECT_IGNORED_LABELS']) ? trim((string)$values['DETECT_IGNORED_LABELS']) : '';
        if ($ignoredStored === '') {
            $values['DETECT_IGNORED_LABELS'] = implode(',', \App\Services\DetectionLabels::defaultIgnoredLabels());
        }

        $faltantes = \App\Services\ConfigService::faltantesRequeridos();

        $this->render('setup', [
            'faltantes' => $faltantes,
            'values' => $values
        ]);
    }

    public function testDb()
    {
        $p = $this->payload();
        $cfg = $this->extraerConfig($p);
        $res = $this->probarDb($cfg);
        $this->jsonResponse($res, $res['ok'] ? 200 : 400);
    }

    public function testSchema()
    {
        $p = $this->payload();
        $cfg = $this->extraerConfig($p);

        $db = $this->probarDb($cfg);
        if (!$db['ok']) {
            $this->jsonResponse($db, 400);
        }

        $res = $this->probarSchemaYTablas($db['pdo'], $cfg);
        // No incluir PDO en la respuesta
        unset($res['pdo']);
        $this->jsonResponse($res, $res['ok'] ? 200 : 400);
    }

    public function testDir()
    {
        $p = $this->payload();
        $cfg = $this->extraerConfig($p);
        $res = $this->probarDirectorio($cfg);
        $this->jsonResponse($res, $res['ok'] ? 200 : 400);
    }

    public function testClasificador()
    {
        $p = $this->payload();
        $cfg = $this->extraerConfig($p);
        $res = $this->probarClasificador($cfg);
        $this->jsonResponse($res, $res['ok'] ? 200 : 400);
    }

    public function save()
    {
        $p = $this->payload();
        $cfg = $this->extraerConfig($p);

        // Normalizar modo
        $mode = isset($cfg['WORKSPACE_MODE']) ? strtolower(trim((string)$cfg['WORKSPACE_MODE'])) : '';
        $mode = ($mode === 'db_and_images' || $mode === 'db') ? 'db_and_images' : 'images_only';
        $cfg['WORKSPACE_MODE'] = $mode;

        $warning = null;
        if ($mode === 'db_and_images') {
            // Reglas: antes de grabar, probar DB + schema obligatoriamente
            $db = $this->probarDb($cfg);
            if (!$db['ok']) $this->jsonResponse(['success' => false, 'ok' => false, 'module' => 'db', 'error' => $db['error']], 400);

            $schema = $this->probarSchemaYTablas($db['pdo'], $cfg);
            if (!$schema['ok']) $this->jsonResponse(['success' => false, 'ok' => false, 'module' => 'schema', 'error' => $schema['error']], 400);

            // Validar selección de campos contra columnas comunes
            $common = $schema['common_columns'] ?? [];
            if (!is_array($common) || empty($common)) {
                $this->jsonResponse(['success' => false, 'ok' => false, 'module' => 'schema', 'error' => 'No se pudieron determinar columnas comunes para validar'], 400);
            }
            $commonMap = [];
            foreach ($common as $c) $commonMap[strtolower((string)$c)] = true;

            $requiredFieldKeys = ['PRIMARY_KEY', 'CAMPO_IDENTIFICADOR', 'CAMPO_USR_ID', 'CAMPO_FECHA', 'COLUMNAS_IMAGEN', 'TABLE_PATTERN', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER'];
            foreach ($requiredFieldKeys as $k) {
                if (!isset($cfg[$k]) || (is_string($cfg[$k]) && trim($cfg[$k]) === '') || (is_array($cfg[$k]) && empty($cfg[$k]))) {
                    $this->jsonResponse(['success' => false, 'ok' => false, 'module' => 'schema', 'error' => "Falta $k"], 400);
                }
            }

            foreach (['PRIMARY_KEY','CAMPO_IDENTIFICADOR','CAMPO_USR_ID','CAMPO_FECHA'] as $k) {
                $v = (string)($cfg[$k] ?? '');
                if ($v === '' || !isset($commonMap[strtolower($v)])) {
                    $this->jsonResponse(['success' => false, 'ok' => false, 'module' => 'schema', 'error' => "El campo seleccionado para {$k} no pertenece a las columnas comunes"], 400);
                }
            }
            if (!empty($cfg['CAMPO_RESULTADO'])) {
                $v = (string)$cfg['CAMPO_RESULTADO'];
                if (!isset($commonMap[strtolower($v)])) {
                    $this->jsonResponse(['success' => false, 'ok' => false, 'module' => 'schema', 'error' => 'CAMPO_RESULTADO no pertenece a las columnas comunes'], 400);
                }
            }

            $imgCols = $cfg['COLUMNAS_IMAGEN'];
            if (is_string($imgCols)) {
                $imgCols = array_filter(array_map('trim', explode(',', $imgCols)));
            }
            if (!is_array($imgCols) || empty($imgCols)) {
                $this->jsonResponse(['success' => false, 'ok' => false, 'module' => 'schema', 'error' => 'Selecciona al menos un campo de imagen'], 400);
            }
            foreach ($imgCols as $c) {
                $c = trim((string)$c);
                if ($c === '' || !isset($commonMap[strtolower($c)])) {
                    $this->jsonResponse(['success' => false, 'ok' => false, 'module' => 'schema', 'error' => 'Una o más columnas de imagen no pertenecen a las columnas comunes'], 400);
                }
            }

            // Normalizar a CSV para persistencia
            $cfg['COLUMNAS_IMAGEN'] = implode(',', array_values(array_unique(array_map('trim', $imgCols))));
        } else {
            // Modo solo imágenes: limpiar config DB/schema para que no quede en "requerido" por error
            foreach (['DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASS','TABLE_PATTERN','PRIMARY_KEY','CAMPO_IDENTIFICADOR','CAMPO_USR_ID','CAMPO_FECHA','COLUMNAS_IMAGEN','CAMPO_RESULTADO','PATRON_MATERIALIZACION'] as $k) {
                $cfg[$k] = '';
            }
        }

        // El clasificador puede estar apagado durante setup: no bloquear el guardado.
        $clas = $this->probarClasificador($cfg);
        if (!$clas['ok']) {
            $warning = 'Clasificador no verificado: ' . ($clas['error'] ?? 'Error');
        }

        // Persistir
        $pairs = $cfg;
        // Normalización de defaults opcionales
        if (empty($pairs['CLASIFICADOR_BASE_URL'])) $pairs['CLASIFICADOR_BASE_URL'] = 'http://localhost:8001/';
        if (!isset($pairs['DETECT_IGNORED_LABELS'])) $pairs['DETECT_IGNORED_LABELS'] = '';
        // Normalizar CSV de labels ignorados
        if (is_array($pairs['DETECT_IGNORED_LABELS'] ?? null)) {
            $items = array_values(array_unique(array_filter(array_map('trim', $pairs['DETECT_IGNORED_LABELS']), fn($x) => $x !== '')));
            $items = array_values(array_unique(array_map(fn($x) => \App\Services\DetectionLabels::normalizeLabel((string)$x), $items)));
            $items = array_values(array_filter($items, fn($x) => $x !== ''));
            $pairs['DETECT_IGNORED_LABELS'] = implode(',', $items);
        } else {
            $raw = trim((string)($pairs['DETECT_IGNORED_LABELS'] ?? ''));
            if ($raw === '') $pairs['DETECT_IGNORED_LABELS'] = '';
            else {
                $items = array_filter(array_map('trim', explode(',', $raw)), fn($x) => $x !== '');
                $items = array_values(array_unique(array_map(fn($x) => \App\Services\DetectionLabels::normalizeLabel((string)$x), $items)));
                $items = array_values(array_filter($items, fn($x) => $x !== ''));
                $pairs['DETECT_IGNORED_LABELS'] = implode(',', $items);
            }
        }

        // Limpiar claves deprecadas si existían en la DB
        $pairs['UMBRAL_CLASIFICACION'] = '';
        $pairs['DETECT_UNSAFE_CLASSES'] = '';

        \App\Services\ConfigService::setMany($pairs);

        $this->jsonResponse([
            'success' => true,
            'ok' => true,
            'mensaje' => 'Configuración guardada correctamente',
            'warning' => $warning
        ]);
    }

    /**
     * Guarda una sección específica de la configuración cuando su "probar" fue OK.
     * Se usa para guardado incremental por secciones en la pantalla de setup.
     */
    public function saveSection()
    {
        $p = $this->payload();
        $cfg = $this->extraerConfig($p);
        $module = strtolower(trim((string)($p['module'] ?? '')));

        if ($module === '') {
            $this->jsonResponse(['success' => false, 'ok' => false, 'error' => 'Falta module'], 400);
        }

        // Normalizar modo si viene en payload
        if (isset($cfg['WORKSPACE_MODE'])) {
            $m = strtolower(trim((string)$cfg['WORKSPACE_MODE']));
            $cfg['WORKSPACE_MODE'] = ($m === 'db_and_images' || $m === 'db') ? 'db_and_images' : 'images_only';
        }

        $pairs = [];
        $saved = [];

        if ($module === 'db') {
            $db = $this->probarDb($cfg);
            if (!$db['ok']) $this->jsonResponse(['success' => false, 'ok' => false, 'module' => 'db', 'error' => $db['error']], 400);

            foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $k) {
                if (array_key_exists($k, $cfg)) {
                    $pairs[$k] = (string)($cfg[$k] ?? '');
                }
            }
        } elseif ($module === 'schema') {
            $db = $this->probarDb($cfg);
            if (!$db['ok']) $this->jsonResponse(['success' => false, 'ok' => false, 'module' => 'db', 'error' => $db['error']], 400);

            $schema = $this->probarSchemaYTablas($db['pdo'], $cfg);
            if (!$schema['ok']) $this->jsonResponse(['success' => false, 'ok' => false, 'module' => 'schema', 'error' => $schema['error']], 400);

            $common = $schema['common_columns'] ?? [];
            $commonMap = [];
            if (is_array($common)) {
                foreach ($common as $c) $commonMap[strtolower((string)$c)] = true;
            }

            // Siempre guardar el patrón si existe
            if (isset($cfg['TABLE_PATTERN']) && trim((string)$cfg['TABLE_PATTERN']) !== '') {
                $pairs['TABLE_PATTERN'] = (string)$cfg['TABLE_PATTERN'];
            }

            // Guardar selecciones solo si son válidas (y no vacías)
            foreach (['PRIMARY_KEY','CAMPO_IDENTIFICADOR','CAMPO_USR_ID','CAMPO_FECHA','CAMPO_RESULTADO'] as $k) {
                if (!isset($cfg[$k])) continue;
                $v = trim((string)$cfg[$k]);
                if ($v === '') continue;
                if (!isset($commonMap[strtolower($v)])) {
                    $this->jsonResponse(['success' => false, 'ok' => false, 'module' => 'schema', 'error' => "El campo {$k} no pertenece a las columnas comunes"], 400);
                }
                $pairs[$k] = $v;
            }

            $imgCols = $cfg['COLUMNAS_IMAGEN'] ?? null;
            if (is_string($imgCols)) {
                $imgCols = array_filter(array_map('trim', explode(',', $imgCols)));
            }
            if (is_array($imgCols) && !empty($imgCols)) {
                foreach ($imgCols as $c) {
                    $c = trim((string)$c);
                    if ($c === '' || !isset($commonMap[strtolower($c)])) {
                        $this->jsonResponse(['success' => false, 'ok' => false, 'module' => 'schema', 'error' => 'Una o más columnas de imagen no pertenecen a las columnas comunes'], 400);
                    }
                }
                $pairs['COLUMNAS_IMAGEN'] = implode(',', array_values(array_unique(array_map('trim', $imgCols))));
            }
        } elseif ($module === 'dir') {
            // Directorio fijo por workspace: no se guarda DIRECTORIO_IMAGENES
            foreach (['PATRON_MATERIALIZACION'] as $k) {
                if (array_key_exists($k, $cfg)) $pairs[$k] = (string)($cfg[$k] ?? '');
            }
        } elseif ($module === 'clasificador') {
            // Normalizar labels ignorados (si vienen)
            if (isset($cfg['DETECT_IGNORED_LABELS'])) {
                $rawItems = $cfg['DETECT_IGNORED_LABELS'];
                if (is_array($rawItems)) {
                    $items = array_values(array_unique(array_filter(array_map('trim', $rawItems), fn($x) => $x !== '')));
                } else {
                    $raw = trim((string)$rawItems);
                    $items = ($raw === '') ? [] : array_filter(array_map('trim', explode(',', $raw)), fn($x) => $x !== '');
                }
                $items = array_values(array_unique(array_map(fn($x) => \App\Services\DetectionLabels::normalizeLabel((string)$x), $items)));
                $items = array_values(array_filter($items, fn($x) => $x !== ''));
                $cfg['DETECT_IGNORED_LABELS'] = implode(',', $items);
            }

            $clas = $this->probarClasificador($cfg);
            if (!$clas['ok']) $this->jsonResponse(['success' => false, 'ok' => false, 'module' => 'clasificador', 'error' => $clas['error']], 400);

            foreach (['CLASIFICADOR_BASE_URL', 'DETECT_IGNORED_LABELS'] as $k) {
                if (array_key_exists($k, $cfg)) {
                    $pairs[$k] = (string)($cfg[$k] ?? '');
                }
            }
            if (empty($pairs['CLASIFICADOR_BASE_URL'])) $pairs['CLASIFICADOR_BASE_URL'] = 'http://localhost:8001/';
            if (!isset($pairs['DETECT_IGNORED_LABELS'])) $pairs['DETECT_IGNORED_LABELS'] = '';

            // Guardar modo si viene (para wizard)
            if (isset($cfg['WORKSPACE_MODE'])) {
                $pairs['WORKSPACE_MODE'] = (string)$cfg['WORKSPACE_MODE'];
            }

            // Limpiar claves deprecadas si existían en la DB
            $pairs['UMBRAL_CLASIFICACION'] = '';
            $pairs['DETECT_UNSAFE_CLASSES'] = '';
        } else {
            $this->jsonResponse(['success' => false, 'ok' => false, 'error' => 'Module inválido'], 400);
        }

        if (empty($pairs)) {
            $this->jsonResponse(['success' => true, 'ok' => true, 'module' => $module, 'saved' => [], 'mensaje' => 'Nada para guardar'], 200);
        }

        \App\Services\ConfigService::setMany($pairs);
        $saved = array_keys($pairs);

        $this->jsonResponse(['success' => true, 'ok' => true, 'module' => $module, 'saved' => $saved], 200);
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

    private function extraerConfig(array $p): array
    {
        $keys = array_merge(
            \App\Services\ConfigService::getKeysRequeridas(),
            \App\Services\ConfigService::getKeysOpcionales()
        );

        $out = [];
        foreach ($keys as $k) {
            if (!array_key_exists($k, $p)) continue;
            if ($k === 'COLUMNAS_IMAGEN') {
                // Puede venir como array desde el multi-select
                if (is_array($p[$k])) {
                    $out[$k] = array_values(array_filter(array_map('trim', $p[$k]), fn($x) => $x !== ''));
                } else {
                    $out[$k] = is_string($p[$k]) ? trim($p[$k]) : (string)$p[$k];
                }
            } elseif ($k === 'DETECT_IGNORED_LABELS') {
                // Puede venir como array (checkboxes múltiples)
                if (is_array($p[$k])) {
                    $out[$k] = array_values(array_filter(array_map('trim', $p[$k]), fn($x) => $x !== ''));
                } else {
                    $out[$k] = is_string($p[$k]) ? trim($p[$k]) : (string)$p[$k];
                }
            } elseif ($k === 'PATRON_MATERIALIZACION') {
                $val = is_string($p[$k]) ? trim($p[$k]) : (string)$p[$k];
                // Guardar patrón sin extensión; la extensión se añade solo al materializar.
                if ($val !== '') {
                    $val = preg_replace('/\.(ext|jpe?g|png|gif|webp|bmp|tiff?|avif|heic|heif|ico|svg)\s*$/i', '', $val) ?? $val;
                    $val = rtrim($val, " \t./");
                }
                $out[$k] = $val;
            } else {
                $out[$k] = is_string($p[$k]) ? trim($p[$k]) : (string)$p[$k];
            }
        }
        return $out;
    }

    private function probarDb(array $cfg): array
    {
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER'] as $k) {
            if (empty($cfg[$k])) {
                return ['success' => true, 'ok' => false, 'module' => 'db', 'error' => "Falta $k"];
            }
        }

        $host = $cfg['DB_HOST'];
        $port = (int)($cfg['DB_PORT'] ?? 3306);
        $dbName = $cfg['DB_NAME'];
        $user = $cfg['DB_USER'];
        $pass = $cfg['DB_PASS'] ?? '';

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->query('SELECT 1')->fetch();
            return ['success' => true, 'ok' => true, 'module' => 'db', 'mensaje' => 'Conexión OK', 'pdo' => $pdo];
        } catch (\Throwable $e) {
            return ['success' => true, 'ok' => false, 'module' => 'db', 'error' => $e->getMessage()];
        }
    }

    /**
     * Busca tablas por patrón. Comodines: ? = un carácter, * = cero o más.
     * Sin comodines = coincidencia exacta.
     */
    private function buscarTablasPorPatron(PDO $pdo, string $dbName, string $pattern): array
    {
        $pattern = trim($pattern);
        $hasWildcards = (strpos($pattern, '?') !== false || strpos($pattern, '*') !== false);

        if (!$hasWildcards) {
            $sql = "SELECT TABLE_NAME
                    FROM INFORMATION_SCHEMA.TABLES
                    WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :pattern
                    ORDER BY TABLE_NAME ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':db' => $dbName, ':pattern' => $pattern]);
        } else {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $pattern);
            $likePattern = str_replace(['*', '?'], ['%', '_'], $escaped);
            $sql = "SELECT TABLE_NAME
                    FROM INFORMATION_SCHEMA.TABLES
                    WHERE TABLE_SCHEMA = :db AND TABLE_NAME LIKE :pattern
                    ORDER BY TABLE_NAME ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':db' => $dbName, ':pattern' => $likePattern]);
        }
        return array_map(fn($r) => $r['TABLE_NAME'], $stmt->fetchAll() ?: []);
    }

    private function columnasDeTabla(PDO $pdo, string $dbName, string $tabla): array
    {
        $sql = "SELECT COLUMN_NAME
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':db' => $dbName, ':t' => $tabla]);
        $cols = [];
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $cols[strtolower((string)$r['COLUMN_NAME'])] = true;
        }
        return $cols;
    }

    private function probarSchemaYTablas(PDO $pdo, array $cfg): array
    {
        foreach (['DB_NAME', 'TABLE_PATTERN'] as $k) {
            if (empty($cfg[$k])) {
                return ['success' => true, 'ok' => false, 'module' => 'schema', 'error' => "Falta $k"];
            }
        }

        try {
            $tablas = $this->buscarTablasPorPatron($pdo, $cfg['DB_NAME'], $cfg['TABLE_PATTERN']);
            if (empty($tablas)) {
                return ['success' => true, 'ok' => false, 'module' => 'schema', 'error' => 'No se encontraron tablas para el patrón'];
            }

            $common = $this->columnasComunesEntreTablas($pdo, $cfg['DB_NAME'], $tablas);
            $commonSorted = $common;
            sort($commonSorted, SORT_NATURAL | SORT_FLAG_CASE);

            return [
                'success' => true,
                'ok' => true,
                'module' => 'schema',
                'mensaje' => 'Tablas OK (columnas comunes calculadas)',
                'total_tablas' => count($tablas),
                'tabla_muestra' => $tablas[0],
                'common_columns' => $commonSorted
            ];
        } catch (\Throwable $e) {
            return ['success' => true, 'ok' => false, 'module' => 'schema', 'error' => $e->getMessage()];
        }
    }

    private function columnasComunesEntreTablas(PDO $pdo, string $dbName, array $tablas): array
    {
        $inter = null;
        foreach ($tablas as $t) {
            $t = (string)$t;
            if ($t === '') continue;
            $colsMap = $this->columnasDeTabla($pdo, $dbName, $t); // lower => true
            if ($inter === null) {
                $inter = $colsMap;
            } else {
                foreach (array_keys($inter) as $k) {
                    if (!isset($colsMap[$k])) {
                        unset($inter[$k]);
                    }
                }
            }
            if (empty($inter)) break;
        }
        if ($inter === null) return [];
        // devolver nombres (en minúsculas) como keys; luego se usan case-insensitive
        return array_keys($inter);
    }

    private function resolverDirectorioImagenes(string $path): string
    {
        // Directorio fijo por workspace (se ignora $path)
        $ws = \App\Services\WorkspaceService::current();
        if ($ws === null) return '';
        \App\Services\WorkspaceService::ensureStructure($ws);
        return \App\Services\WorkspaceService::paths($ws)['imagesDir'] ?? '';
    }

    private function probarDirectorio(array $cfg): array
    {
        $dir = $this->resolverDirectorioImagenes((string)($cfg['DIRECTORIO_IMAGENES'] ?? ''));
        if ($dir === '') {
            return ['success' => true, 'ok' => false, 'module' => 'dir', 'error' => 'No hay workspace activo para resolver images/'];
        }

        try {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (!is_dir($dir)) {
                return ['success' => true, 'ok' => false, 'module' => 'dir', 'error' => "No se pudo crear el directorio: {$dir}"];
            }
            $testFile = rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . '__test_write_' . uniqid() . '.txt';
            @file_put_contents($testFile, 'ok');
            $ok = file_exists($testFile);
            @unlink($testFile);
            if (!$ok) {
                return ['success' => true, 'ok' => false, 'module' => 'dir', 'error' => "No se pudo escribir en: {$dir}"];
            }
            return ['success' => true, 'ok' => true, 'module' => 'dir', 'mensaje' => 'Directorio OK', 'ruta' => $dir];
        } catch (\Throwable $e) {
            return ['success' => true, 'ok' => false, 'module' => 'dir', 'error' => $e->getMessage()];
        }
    }

    private function faviconPath(): string
    {
        // Usar el favicon real del proyecto para el test del clasificador
        return __DIR__ . '/../../public/img/favicon.png';
    }

    private function probarClasificador(array $cfg): array
    {
        if (!function_exists('curl_init')) {
            return ['success' => true, 'ok' => false, 'module' => 'clasificador', 'error' => 'La extensión cURL no está habilitada en PHP'];
        }

        $baseUrl = $cfg['CLASIFICADOR_BASE_URL'] ?? 'http://localhost:8001/';
        $baseUrl = rtrim(trim((string)$baseUrl), '/');
        if ($baseUrl === '') $baseUrl = 'http://localhost:8001/';

        $url = $baseUrl . '/health';

        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['accept: application/json'],
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_TIMEOUT => 20
            ]);
            $body = curl_exec($ch);
            $curlErr = curl_error($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($body === false || $curlErr) {
                return ['success' => true, 'ok' => false, 'module' => 'clasificador', 'error' => ($curlErr ?: 'Error desconocido'), 'http_code' => $httpCode];
            }
            $json = json_decode($body ?: '', true);
            if (!is_array($json)) {
                $preview = substr((string)$body, 0, 300);
                return ['success' => true, 'ok' => false, 'module' => 'clasificador', 'error' => "HTTP {$httpCode}: Respuesta no-JSON o inválida", 'http_code' => $httpCode, 'raw_preview' => $preview];
            }

            $ok = null;
            if (array_key_exists('ok', $json)) {
                $ok = (bool)$json['ok'];
            } elseif ($httpCode >= 200 && $httpCode < 300) {
                // Fallback: si responde 2xx con JSON, asumir health OK
                $ok = true;
            } else {
                $ok = false;
            }

            if (!$ok) {
                return ['success' => true, 'ok' => false, 'module' => 'clasificador', 'error' => 'Health no OK', 'http_code' => $httpCode, 'raw' => $json];
            }

            return [
                'success' => true,
                'ok' => true,
                'module' => 'clasificador',
                'mensaje' => 'Health OK',
                'http_code' => $httpCode
            ];
        } catch (\Throwable $e) {
            return ['success' => true, 'ok' => false, 'module' => 'clasificador', 'error' => $e->getMessage()];
        }
    }

    // La configuración vive en SQLite (app_config)
}

