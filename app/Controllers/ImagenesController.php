<?php

namespace App\Controllers;

use App\Models\ImagenesIndex;
use App\Models\CarpetasIndex;
use App\Services\LogService;
use App\Services\SqliteConnection;
use App\Services\SqliteSchema;

class ImagenesController extends BaseController
{
    private function limpiarDirectorioImagenes(string $directorioBase): array
    {
        $out = [
            'deleted_invalid_files' => 0,
            'deleted_invalid_errors' => 0,
            'deleted_empty_dirs' => 0,
            'deleted_empty_dir_errors' => 0
        ];

        $baseReal = realpath($directorioBase) ?: $directorioBase;
        $baseReal = rtrim($baseReal, "/\\");
        if ($baseReal === '' || !is_dir($baseReal)) return $out;

        // Validación de imagen: extensión permitida + header válido (getimagesize o mime).
        $extOk = ['jpg' => true, 'jpeg' => true, 'png' => true, 'gif' => true, 'webp' => true, 'bmp' => true];
        $hasGetImageSize = function_exists('getimagesize');
        $hasMime = function_exists('mime_content_type');

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseReal, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        // 1) Borrar archivos que no sean imagen válida
        foreach ($iter as $node) {
            try {
                if (!$node->isFile()) continue;
                $path = $node->getPathname();

                $pathReal = realpath($path) ?: $path;
                $pathNorm = str_replace('\\', '/', $pathReal);
                $baseNorm = str_replace('\\', '/', $baseReal);
                $pathNormLower = strtolower($pathNorm);
                $baseNormLower = strtolower($baseNorm);
                $isInside = (strpos($pathNormLower, $baseNormLower . '/') === 0) || ($pathNormLower === $baseNormLower);
                if (!$isInside) continue;

                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $isValid = isset($extOk[$ext]);
                if ($isValid) {
                    $validHeader = false;
                    if ($hasGetImageSize) {
                        $info = @getimagesize($path);
                        $validHeader = ($info !== false);
                    }
                    if (!$validHeader && $hasMime) {
                        $mime = @mime_content_type($path);
                        $validHeader = (is_string($mime) && stripos($mime, 'image/') === 0);
                    }
                    $isValid = $validHeader;
                }

                if (!$isValid) {
                    if (@unlink($path)) {
                        $out['deleted_invalid_files']++;
                    } else {
                        $out['deleted_invalid_errors']++;
                    }
                }
            } catch (\Throwable $e) {
                $out['deleted_invalid_errors']++;
            }
        }

        // 2) Eliminar carpetas vacías (bottom-up)
        $iterDirs = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseReal, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterDirs as $node) {
            try {
                if (!$node->isDir()) continue;
                $dir = $node->getPathname();
                $dirReal = realpath($dir) ?: $dir;
                $dirReal = rtrim($dirReal, "/\\");
                if ($dirReal === '' || strcasecmp($dirReal, $baseReal) === 0) continue; // no borrar raíz

                // ¿Vacía?
                $isEmpty = true;
                $dh = @opendir($dirReal);
                if ($dh !== false) {
                    while (($entry = readdir($dh)) !== false) {
                        if ($entry === '.' || $entry === '..') continue;
                        $isEmpty = false;
                        break;
                    }
                    closedir($dh);
                }
                if ($isEmpty) {
                    if (@rmdir($dirReal)) {
                        $out['deleted_empty_dirs']++;
                    } else {
                        $out['deleted_empty_dir_errors']++;
                    }
                }
            } catch (\Throwable $e) {
                $out['deleted_empty_dir_errors']++;
            }
        }

        return $out;
    }

    public function estadisticas()
    {
        try {
            $imagenesIndex = new ImagenesIndex();
            $stats = $imagenesIndex->getStats(true);
            $statsDet = $imagenesIndex->getStatsDeteccion(true);
            $stats['pendientes_deteccion'] = (int)($statsDet['pendientes'] ?? 0);
            $pdo = \App\Services\SqliteConnection::get();
            $detCount = $pdo->query("SELECT COUNT(*) FROM detections")->fetchColumn();
            $stats['detections_total'] = ($detCount !== false) ? (int)$detCount : 0;

            $this->jsonResponse([
                'success' => true,
                'stats' => $stats
            ]);
        } catch (\Exception $e) {
            $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function procesarSiguiente()
    {
        try {
            if (!function_exists('curl_init')) {
                $this->jsonResponse([
                    'success' => false,
                    'error' => 'La extensión cURL no está habilitada en PHP'
                ], 500);
            }

            $imagenesIndex = new ImagenesIndex();
            $siguiente = $imagenesIndex->obtenerSiguientePendienteProcesar();

            if ($siguiente === null) {
                $stats = $imagenesIndex->getStats(true);
                $statsDet = $imagenesIndex->getStatsDeteccion(true);
                $pdo = \App\Services\SqliteConnection::get();
                $detCount = $pdo->query("SELECT COUNT(*) FROM detections")->fetchColumn();
                $detectionsTotal = ($detCount !== false) ? (int)$detCount : 0;
                $this->jsonResponse([
                    'success' => true,
                    'procesada' => false,
                    'mensaje' => 'No hay imágenes pendientes por procesar',
                    'pendientes' => (int)($stats['pendientes'] ?? 0),
                    'pendientes_clasificacion' => (int)($stats['pendientes'] ?? 0),
                    'pendientes_deteccion' => (int)($statsDet['pendientes'] ?? 0),
                    'detections_total' => $detectionsTotal,
                    'stats' => $stats,
                    'stats_deteccion' => $statsDet
                ]);
            }

            [$key, $record, $fase] = $siguiente;
            $rutaCompleta = $record['ruta_completa'] ?? null;

            if (!$rutaCompleta || !file_exists($rutaCompleta) || !is_file($rutaCompleta)) {
                $imagenesIndex->marcarError($key, 'El archivo no existe en disco');
                $stats = $imagenesIndex->getStats(true);
                $statsDet = $imagenesIndex->getStatsDeteccion(true);
                $pendientesClas = (int)($stats['pendientes'] ?? 0);
                $pendientesDet = (int)($statsDet['pendientes'] ?? 0);
                $pdo = \App\Services\SqliteConnection::get();
                $detCount = $pdo->query("SELECT COUNT(*) FROM detections")->fetchColumn();
                $detectionsTotal = ($detCount !== false) ? (int)$detCount : 0;
                $this->jsonResponse([
                    'success' => true,
                    'tag' => 'error',
                    'procesada' => true,
                    'fase' => $fase,
                    'error' => 'La imagen no existe en disco',
                    'ruta_relativa' => $record['ruta_relativa'] ?? $key,
                    'pendientes' => $pendientesClas,
                    'pendientes_clasificacion' => $pendientesClas,
                    'pendientes_deteccion' => $pendientesDet,
                    'pendientes_total' => ($pendientesClas + $pendientesDet),
                    'detections_total' => $detectionsTotal,
                    'stats' => $stats,
                    'stats_deteccion' => $statsDet
                ]);
            }

            $baseUrl = \App\Services\ConfigService::obtenerOpcional('CLASIFICADOR_BASE_URL', 'http://localhost:8001/');
            $baseUrl = rtrim(trim($baseUrl), '/');
            $urlDetect = $baseUrl . '/detect';

            $mime = @mime_content_type($rutaCompleta) ?: 'application/octet-stream';
            $cfile = new \CURLFile($rutaCompleta, $mime, basename($rutaCompleta));

            $safe = null;
            $unsafe = null;
            $resultado = null;
            $detectOk = null;
            $detectError = null;
            $detecciones = [];
            $etiquetas = [];

            // Config: labels a ignorar (lista negra). Si una imagen SOLO tiene labels ignorados => safe.
            $ignoredCsv = (string)\App\Services\ConfigService::obtenerOpcional('DETECT_IGNORED_LABELS', '');
            $ignoredList = array_filter(array_map('trim', explode(',', $ignoredCsv)), fn($x) => $x !== '');
            $ignoredMap = [];
            foreach ($ignoredList as $c) {
                $k = \App\Services\DetectionLabels::normalizeLabel((string)$c);
                if ($k !== '') $ignoredMap[$k] = true;
            }

            $extractDetections = function ($json2): ?array {
                if (!is_array($json2)) return null;
                // NudeNet: {success:true, prediction:[...]}
                if (isset($json2['prediction']) && is_array($json2['prediction'])) return $json2['prediction'];
                // Lista directa []
                if (empty($json2) || array_keys($json2) === range(0, count($json2) - 1)) return $json2;
                // Formatos legacy
                if (isset($json2['parts']) && is_array($json2['parts'])) return $json2['parts'];
                if (isset($json2['detections']) && is_array($json2['detections'])) return $json2['detections'];
                return null;
            };

            $normalizeDetection = function ($d): ?array {
                if (!is_array($d)) return null;
                $label = '';
                if (isset($d['label'])) $label = (string)$d['label'];
                elseif (isset($d['class'])) $label = (string)$d['class'];
                $label = \App\Services\DetectionLabels::normalizeLabel($label);
                if ($label === '') return null;

                $score = null;
                if (isset($d['score']) && is_numeric($d['score'])) $score = (float)$d['score'];
                if ($score === null) return null;

                $box = null;
                if (isset($d['box']) && is_array($d['box']) && count($d['box']) === 4) {
                    $b = array_values($d['box']);
                    $x1 = is_numeric($b[0]) ? (float)$b[0] : null;
                    $y1 = is_numeric($b[1]) ? (float)$b[1] : null;
                    $x2 = is_numeric($b[2]) ? (float)$b[2] : null;
                    $y2 = is_numeric($b[3]) ? (float)$b[3] : null;
                    if ($x1 !== null && $y1 !== null && $x2 !== null && $y2 !== null) {
                        // Soportar [x,y,w,h] además de [x1,y1,x2,y2]
                        if ($x2 <= $x1 || $y2 <= $y1) {
                            // interpretar como width/height si parece invertido
                            if ($x2 > 0 && $y2 > 0) {
                                $x2 = $x1 + $x2;
                                $y2 = $y1 + $y2;
                            }
                        }
                        // Normalizar min/max
                        $minX = min($x1, $x2);
                        $maxX = max($x1, $x2);
                        $minY = min($y1, $y2);
                        $maxY = max($y1, $y2);
                        $box = [(int)round($minX), (int)round($minY), (int)round($maxX), (int)round($maxY)];
                    }
                }

                return [
                    'label' => $label,
                    'score' => $score,
                    'box' => $box
                ];
            };

            // --- Detección (/detect) SIEMPRE ---
            $ch2 = curl_init();
            curl_setopt_array($ch2, [
                CURLOPT_URL => $urlDetect,
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'accept: application/json'
                ],
                CURLOPT_POSTFIELDS => [
                    'file' => $cfile
                ],
                CURLOPT_TIMEOUT => 60
            ]);

            $body2 = curl_exec($ch2);
            $curlErr2 = curl_error($ch2);
            $httpCode2 = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);

            if ($body2 === false || $curlErr2) {
                $detectOk = false;
                $detectError = 'Error al llamar /detect: ' . ($curlErr2 ?: 'desconocido');
                $imagenesIndex->marcarDeteccionError($key, $detectError);
            } else {
                $json2 = json_decode($body2 ?: '', true);
                $rawDet = $extractDetections($json2);
                if ($rawDet === null) {
                    $detectOk = false;
                    $detectError = 'Respuesta inválida de /detect';
                    $imagenesIndex->marcarDeteccionError($key, $detectError . ' (http ' . $httpCode2 . ')');
                } else {
                    $norm = [];
                    foreach ($rawDet as $d) {
                        $n = $normalizeDetection($d);
                        if ($n) $norm[] = $n;
                    }
                    // Ignorar labels en lista negra
                    $detecciones = array_values(array_filter($norm, function ($d) use ($ignoredMap) {
                        if (!is_array($d)) return false;
                        $lab = (string)($d['label'] ?? '');
                        if ($lab === '') return false;
                        return !isset($ignoredMap[$lab]);
                    }));

                    // Derivar etiquetas
                    $tmp = [];
                    foreach ($detecciones as $d) {
                        $lab = (string)($d['label'] ?? '');
                        if ($lab !== '') $tmp[$lab] = true;
                    }
                    $etiquetas = array_keys($tmp);

                    // Nuevo criterio: unsafe = cualquier detección no ignorada.
                    $unsafeScore = 0.0;
                    foreach ($detecciones as $d) {
                        $sc = isset($d['score']) && is_numeric($d['score']) ? (float)$d['score'] : null;
                        if ($sc === null) continue;
                        if ($sc > $unsafeScore) $unsafeScore = $sc;
                    }

                    $resultado = (count($detecciones) > 0) ? 'unsafe' : 'safe';
                    $safe = ($resultado === 'safe') ? 1.0 : 0.0;
                    $unsafe = ($resultado === 'unsafe') ? $unsafeScore : 0.0;

                    $detectOk = true;
                    $imagenesIndex->marcarDeteccion($key, $detecciones, $resultado, $unsafeScore);
                }
            }

            $stats = $imagenesIndex->getStats(true);
            $statsDet = $imagenesIndex->getStatsDeteccion(true);
            $pendientesClas = (int)($stats['pendientes'] ?? 0);
            $pendientesDet = (int)($statsDet['pendientes'] ?? 0);
            $pendientesTotal = $pendientesClas + $pendientesDet;
            $pdo = \App\Services\SqliteConnection::get();
            $detCount = $pdo->query("SELECT COUNT(*) FROM detections")->fetchColumn();
            $detectionsTotal = ($detCount !== false) ? (int)$detCount : 0;

            $rutaRel = $record['ruta_relativa'] ?? $key;
            $logMsg = 'Procesada: ' . $rutaRel . ' · resultado: ' . ($resultado ?? '');
            if (($resultado === 'unsafe') && !empty($detecciones)) {
                $ordenadas = $detecciones;
                usort($ordenadas, fn($a, $b) => (float)($b['score'] ?? 0) <=> (float)($a['score'] ?? 0));
                $tagsConPct = [];
                foreach ($ordenadas as $d) {
                    $lab = (string)($d['label'] ?? '');
                    if ($lab === '') continue;
                    $pct = (int)round(((float)($d['score'] ?? 0)) * 100);
                    $tagsConPct[] = $lab . ' ' . $pct . '%';
                }
                if (!empty($tagsConPct)) {
                    $logMsg .= ' · etiquetas: ' . implode(', ', $tagsConPct);
                }
            }
            LogService::append([
                'type' => 'info',
                'message' => $logMsg,
            ]);

            $this->jsonResponse([
                'success' => true,
                'procesada' => true,
                'fase' => $fase,
                'ruta_relativa' => $rutaRel,
                'ruta_carpeta' => $record['ruta_carpeta'] ?? '',
                'safe' => $safe,
                'unsafe' => $unsafe,
                'resultado' => $resultado,
                'detect' => [
                    'ok' => $detectOk,
                    'error' => $detectError,
                    'count' => is_array($detecciones) ? count($detecciones) : null,
                    'etiquetas' => $etiquetas
                ],
                // Pendientes que se muestran en el panel (coinciden con stats.pendientes)
                'pendientes' => $pendientesClas,
                'pendientes_clasificacion' => $pendientesClas,
                'pendientes_deteccion' => $pendientesDet,
                // Compat/debug: suma de pendientes
                'pendientes_total' => $pendientesTotal,
                'detections_total' => $detectionsTotal,
                'stats' => $stats,
                'stats_deteccion' => $statsDet
            ]);
        } catch (\Exception $e) {
            $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function etiquetasDetectadas()
    {
        try {
            $imagenesIndex = new ImagenesIndex();
            $etiquetas = $imagenesIndex->getEtiquetasDetectadas(true);

            $this->jsonResponse([
                'success' => true,
                'total' => count($etiquetas),
                'etiquetas' => $etiquetas
            ]);
        } catch (\Exception $e) {
            $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function buscarPorEtiquetas()
    {
        try {
            $labelsStr = $_GET['labels'] ?? '';
            $umbralDefault = 0;
            $umbral = isset($_GET['umbral']) ? (int)$_GET['umbral'] : $umbralDefault;
            $umbral = max(0, min(100, $umbral));

            $labelsStr = trim((string)$labelsStr);
            $labels = [];
            if ($labelsStr !== '') {
                $labels = array_filter(array_map('trim', explode(',', $labelsStr)));
            }

            if (empty($labels)) {
                $this->jsonResponse([
                    'success' => false,
                    'error' => 'Parámetro labels requerido (csv)'
                ], 400);
            }

            $imagenesIndex = new ImagenesIndex();
            $resultados = $imagenesIndex->buscarPorEtiquetas($labels, $umbral);

            $this->jsonResponse([
                'success' => true,
                'labels' => $labels,
                'umbral' => $umbral,
                'total' => count($resultados),
                'imagenes' => $resultados
            ]);
        } catch (\Exception $e) {
            $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Full sync del índice SQLite desde el filesystem (workspaces/<ws>/images).
     * Útil para imágenes subidas o copiadas manualmente al workspace.
     */
    public function reindexDesdeFilesystem()
    {
        try {
            $t0 = microtime(true);

            $imagenesIndex = new ImagenesIndex();
            $directorioBase = ImagenesIndex::resolverDirectorioBaseImagenes();
            $cleanup = $this->limpiarDirectorioImagenes($directorioBase);
            $delta = $imagenesIndex->sincronizarDesdeDirectorio($directorioBase);

            // Dedupe por MD5: mantener la copia más antigua (mtime menor) y borrar duplicados del disco + SQLite
            $duplicatesRemoved = [
                'deleted_files' => 0,
                'deleted_rows' => 0,
                'kept' => 0
            ];
            try {
                $baseReal = realpath($directorioBase) ?: $directorioBase;
                $baseNorm = str_replace('\\', '/', rtrim($baseReal, "/\\"));
                $baseNormLower = strtolower($baseNorm);

                $pdo = SqliteConnection::get();
                SqliteSchema::ensure($pdo);

                $rows = $pdo->query("
                    SELECT ruta_relativa, ruta_completa, mtime
                    FROM images
                    ORDER BY COALESCE(mtime, 0) ASC, ruta_relativa ASC
                ")->fetchAll();

                $seen = [];
                $pdo->beginTransaction();
                try {
                    $stmtSet = $pdo->prepare("UPDATE images SET content_md5 = :m WHERE ruta_relativa = :k");
                    $stmtDel = $pdo->prepare("DELETE FROM images WHERE ruta_relativa = :k");

                    foreach ($rows as $r) {
                        $k = (string)($r['ruta_relativa'] ?? '');
                        $full = (string)($r['ruta_completa'] ?? '');
                        if ($k === '' || $full === '') continue;
                        if (!is_file($full)) continue;

                        $md5 = @md5_file($full);
                        if (!is_string($md5) || strlen($md5) !== 32) continue;
                        $md5 = strtolower($md5);

                        if (!isset($seen[$md5])) {
                            // keep
                            $seen[$md5] = $k;
                            try {
                                $stmtSet->execute([':m' => $md5, ':k' => $k]);
                            } catch (\Throwable $e) {
                                // Si falla por unique/lock, no bloquear dedupe
                            }
                            $duplicatesRemoved['kept']++;
                            continue;
                        }

                        // duplicate -> delete
                        $fullReal = realpath($full) ?: $full;
                        $fullNorm = str_replace('\\', '/', $fullReal);
                        $fullNormLower = strtolower($fullNorm);
                        $isInside = (strpos($fullNormLower, $baseNormLower . '/') === 0) || ($fullNormLower === $baseNormLower);
                        if ($isInside) {
                            if (@unlink($fullReal)) {
                                $duplicatesRemoved['deleted_files']++;
                            }
                        }

                        $stmtDel->execute([':k' => $k]);
                        $duplicatesRemoved['deleted_rows']++;
                    }

                    $pdo->commit();
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }

                // Recalcular carpetas tras borrar duplicados
                try {
                    (new CarpetasIndex())->regenerarDesdeImagenes(new ImagenesIndex());
                } catch (\Throwable $e) {
                    // silencioso
                }
            } catch (\Throwable $e) {
                // silencioso: no bloquear el reindex completo si el dedupe falla
            }

            $stats = $imagenesIndex->getStats(true);
            $statsDet = $imagenesIndex->getStatsDeteccion(true);

            $elapsedMs = (int)round((microtime(true) - $t0) * 1000);

            $nuevas = (int)($delta['nuevas'] ?? 0);
            $existentes = (int)($delta['existentes'] ?? 0);
            LogService::append([
                'type' => 'success',
                'message' => 'Reindex completado. Nuevas: ' . $nuevas . ', existentes: ' . $existentes . ' (' . $elapsedMs . ' ms)',
            ]);

            $this->jsonResponse([
                'success' => true,
                'directorio_base' => $directorioBase,
                'cleanup' => $cleanup,
                'delta' => $delta,
                'duplicates_removed' => $duplicatesRemoved,
                'stats' => $stats,
                'stats_deteccion' => $statsDet,
                'elapsed_ms' => $elapsedMs
            ]);
        } catch (\Exception $e) {
            $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Soft reset: conserva el índice de archivos (images/folders),
     * pero borra resultados de clasificación y detecciones.
     */
    public function resetClasificacion()
    {
        try {
            $t0 = microtime(true);
            $now = date('Y-m-d H:i:s');

            $pdo = SqliteConnection::get();
            SqliteSchema::ensure($pdo);

            $pdo->beginTransaction();
            try {
                $pdo->exec('DELETE FROM detections');
                $stmt = $pdo->prepare("
                    UPDATE images
                    SET
                      clasif_estado='pending',
                      safe=NULL,
                      unsafe=NULL,
                      resultado=NULL,
                      clasif_error=NULL,
                      clasif_en=NULL,
                      detect_requerida=1,
                      detect_estado='pending',
                      detect_error=NULL,
                      detect_en=NULL,
                      actualizada_en=:t
                ");
                $stmt->execute([':t' => $now]);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            // Recalcular agregados y stats
            $imagenesIndex = new ImagenesIndex();
            try {
                (new CarpetasIndex())->regenerarDesdeImagenes($imagenesIndex);
            } catch (\Throwable $e) {
                // No bloquear el reset por fallo en folders; se puede recalcular después
            }

            $stats = $imagenesIndex->getStats(true);
            $statsDet = $imagenesIndex->getStatsDeteccion(true);
            $elapsedMs = (int)round((microtime(true) - $t0) * 1000);

            LogService::append([
                'type' => 'warning',
                'message' => 'Reset de clasificación y detecciones completado (' . $elapsedMs . ' ms)',
            ]);

            $this->jsonResponse([
                'success' => true,
                'reset_at' => $now,
                'stats' => $stats,
                'stats_deteccion' => $statsDet,
                'elapsed_ms' => $elapsedMs
            ]);
        } catch (\Exception $e) {
            $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function imagenDetecciones()
    {
        try {
            $ruta = isset($_GET['ruta']) ? (string)$_GET['ruta'] : '';
            $archivo = isset($_GET['archivo']) ? (string)$_GET['archivo'] : '';
            $rutaRelativa = isset($_GET['ruta_relativa']) ? (string)$_GET['ruta_relativa'] : '';

            // Normalizar / validar path traversal
            foreach ([$ruta, $archivo, $rutaRelativa] as $s) {
                if ($s !== '' && preg_match('/\.\./', $s)) {
                    $this->jsonResponse(['success' => false, 'error' => 'Ruta inválida'], 400);
                }
            }

            $ruta = str_replace('\\', '/', trim($ruta));
            $ruta = trim($ruta, '/');
            $archivo = trim($archivo);

            if ($rutaRelativa === '') {
                if ($archivo === '') {
                    $this->jsonResponse(['success' => false, 'error' => 'Faltan parámetros (archivo o ruta_relativa)'], 400);
                }
                $rutaRelativa = ($ruta !== '') ? ($ruta . '/' . $archivo) : $archivo;
            }

            $pdo = SqliteConnection::get();
            SqliteSchema::ensure($pdo);

            $stmtImg = $pdo->prepare("SELECT ruta_relativa, archivo, ruta_carpeta, clasif_estado, detect_estado FROM images WHERE ruta_relativa = :k LIMIT 1");
            $stmtImg->execute([':k' => $rutaRelativa]);
            $img = $stmtImg->fetch();
            if (!$img) {
                $this->jsonResponse([
                    'success' => true,
                    'found' => false,
                    'ruta_relativa' => $rutaRelativa,
                    'pending' => true,
                    'detections' => []
                ]);
            }

            $detEstado = (string)($img['detect_estado'] ?? '');
            $clasEstado = (string)($img['clasif_estado'] ?? '');
            $pending = ($detEstado !== 'ok') || ($clasEstado === 'pending');

            $stmtDet = $pdo->prepare("
                SELECT label, score, x1, y1, x2, y2
                FROM detections
                WHERE image_ruta_relativa = :k
                ORDER BY score DESC, label ASC
            ");
            $stmtDet->execute([':k' => $rutaRelativa]);
            $rows = $stmtDet->fetchAll() ?: [];
            $out = [];
            foreach ($rows as $r) {
                $box = null;
                if ($r['x1'] !== null && $r['y1'] !== null && $r['x2'] !== null && $r['y2'] !== null) {
                    $box = [(int)$r['x1'], (int)$r['y1'], (int)$r['x2'], (int)$r['y2']];
                }
                $out[] = [
                    'label' => (string)($r['label'] ?? ''),
                    'score' => (float)($r['score'] ?? 0),
                    'box' => $box
                ];
            }

            $this->jsonResponse([
                'success' => true,
                'found' => true,
                'ruta_relativa' => (string)($img['ruta_relativa'] ?? $rutaRelativa),
                'ruta_carpeta' => (string)($img['ruta_carpeta'] ?? ''),
                'archivo' => (string)($img['archivo'] ?? ''),
                'pending' => $pending,
                'detections' => $out
            ]);
        } catch (\Exception $e) {
            $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}

