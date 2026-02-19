<?php

namespace App\Controllers;

use App\Models\CarpetasIndex;
use PDO;
use App\Services\StringNormalizer;
use App\Services\AppConnection;
use App\Services\AppSchema;
use App\Services\WorkspaceService;

class CarpetasController extends BaseController
{
    private function workspacePath(string $suffix = ''): string
    {
        $ws = WorkspaceService::current();
        if ($ws === null) {
            // fallback legacy
            $base = __DIR__ . '/../../tmp';
            if ($suffix === '') return $base;
            return rtrim($base, "/\\") . DIRECTORY_SEPARATOR . ltrim($suffix, "/\\");
        }
        WorkspaceService::ensureStructure($ws);
        $paths = WorkspaceService::paths($ws);
        $base = $paths['cacheDir'] ?? (__DIR__ . '/../../tmp');
        if ($suffix === '') return $base;
        return rtrim($base, "/\\") . DIRECTORY_SEPARATOR . ltrim($suffix, "/\\");
    }

    private function ensureDir(string $dir): bool
    {
        if (is_dir($dir)) return true;
        @mkdir($dir, 0755, true);
        return is_dir($dir);
    }

    private function loadImageResource(string $path, ?string &$mime = null, ?array &$size = null)
    {
        $mime = null;
        $size = null;
        if (!is_file($path)) return null;

        if (function_exists('getimagesize')) {
            $info = @getimagesize($path);
            if (is_array($info) && isset($info['mime'])) {
                $mime = (string)$info['mime'];
                $size = ['w' => (int)($info[0] ?? 0), 'h' => (int)($info[1] ?? 0)];
            }
        }

        // Fallback mime
        if (!$mime && function_exists('mime_content_type')) {
            $m = @mime_content_type($path);
            if (is_string($m) && $m !== '') $mime = $m;
        }

        // Crear recurso según mime/extensiones comunes
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $try = [];
        if ($mime) $try[] = strtolower($mime);
        $try[] = $ext;

        foreach ($try as $t) {
            if ($t === 'image/jpeg' || $t === 'jpeg' || $t === 'jpg') {
                if (function_exists('imagecreatefromjpeg')) return @imagecreatefromjpeg($path);
            }
            if ($t === 'image/png' || $t === 'png') {
                if (function_exists('imagecreatefrompng')) return @imagecreatefrompng($path);
            }
            if ($t === 'image/gif' || $t === 'gif') {
                if (function_exists('imagecreatefromgif')) return @imagecreatefromgif($path);
            }
            if ($t === 'image/webp' || $t === 'webp') {
                if (function_exists('imagecreatefromwebp')) return @imagecreatefromwebp($path);
            }
            if ($t === 'image/bmp' || $t === 'bmp') {
                if (function_exists('imagecreatefrombmp')) return @imagecreatefrombmp($path);
            }
        }

        return null;
    }

    /**
     * @param bool $isThumb Si es thumbnail de galería
     * @param bool|null $thumbFromCache Si es thumb: true = servido desde caché, false = recién generado (para cabecera X-Thumb-Cached)
     */
    private function outputFile(string $path, string $contentType, bool $isThumb = false, ?bool $thumbFromCache = null): void
    {
        if (!is_file($path)) {
            http_response_code(404);
            die('Archivo no encontrado');
        }
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . filesize($path));
        if ($isThumb) {
            header('Cache-Control: public, max-age=31536000, immutable');
            if ($thumbFromCache === false) {
                header('X-Thumb-New: 1');
                header('Access-Control-Expose-Headers: X-Thumb-New, X-Thumb-Cached');
            } elseif ($thumbFromCache === true) {
                header('X-Thumb-Cached: 1');
                header('Access-Control-Expose-Headers: X-Thumb-New, X-Thumb-Cached');
            }
        }
        readfile($path);
        exit;
    }

    private function enriquecerCarpetasConTags(array $carpetas): array
    {
        if (empty($carpetas)) return $carpetas;

        $rutas = [];
        foreach ($carpetas as $c) {
            if (!is_array($c)) continue;
            $r = (string)($c['ruta'] ?? '');
            if ($r === '') continue;
            $rutas[] = $r;
        }
        $rutas = array_values(array_unique($rutas));
        if (empty($rutas)) return $carpetas;

        $pdo = AppConnection::get();
        AppSchema::ensure($pdo);
        $tImg = AppConnection::table('images');
        $tDet = AppConnection::table('detections');

        $ph = implode(',', array_fill(0, count($rutas), '?'));

        // Imágenes: ruta_relativa, ruta_carpeta para las carpetas
        $stmtImg = $pdo->prepare("
            SELECT ruta_relativa, ruta_carpeta
            FROM {$tImg}
            WHERE COALESCE(ruta_carpeta,'') IN ($ph)
        ");
        $stmtImg->execute($rutas);
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

        $tagsByRuta = []; // ruta => [label => count] (count = imágenes distintas por label)
        if (!empty($rutaRelativas)) {
            $ph2 = implode(',', array_fill(0, count($rutaRelativas), '?'));
            $stmtDet = $pdo->prepare("
                SELECT image_ruta_relativa, label
                FROM {$tDet}
                WHERE image_ruta_relativa IN ($ph2)
            ");
            $stmtDet->execute($rutaRelativas);
            $detRows = $stmtDet->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $byRutaLabel = []; // clave "ruta\0label" => set de image_ruta_relativa
            foreach ($detRows as $dr) {
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

        // Pendientes por carpeta
        $stmtPend = $pdo->prepare("
            SELECT i.ruta_carpeta as ruta, COUNT(*) as c
            FROM {$tImg} i
            WHERE COALESCE(i.ruta_carpeta,'') IN ($ph)
              AND (
                COALESCE(i.detect_estado,'') <> 'ok'
                OR COALESCE(i.clasif_estado,'') = 'pending'
              )
            GROUP BY i.ruta_carpeta
        ");
        $stmtPend->execute($rutas);
        $rowsPend = $stmtPend->fetchAll() ?: [];
        $pendByRuta = [];
        foreach ($rowsPend as $r) {
            $ruta = (string)($r['ruta'] ?? '');
            $cnt = (int)($r['c'] ?? 0);
            if ($ruta === '') continue;
            $pendByRuta[$ruta] = $cnt;
        }

        // Adjuntar a cada carpeta
        $out = [];
        foreach ($carpetas as $c) {
            if (!is_array($c)) continue;
            $ruta = (string)($c['ruta'] ?? '');
            $tagsMap = $tagsByRuta[$ruta] ?? [];
            $tagsList = [];
            foreach ($tagsMap as $lab => $cnt) {
                $tagsList[] = ['label' => $lab, 'count' => (int)$cnt];
            }
            usort($tagsList, function ($a, $b) {
                $ca = (int)($a['count'] ?? 0);
                $cb = (int)($b['count'] ?? 0);
                if ($ca !== $cb) return $cb <=> $ca;
                return strcmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
            });
            // limitar para UI
            $tagsList = array_slice($tagsList, 0, 12);

            $c['pendientes'] = (int)($pendByRuta[$ruta] ?? 0);
            $c['tags'] = $tagsList;
            $out[] = $c;
        }

        $out = $this->enriquecerCarpetasConAvatares($out);
        return $out;
    }

    /**
     * Para cada carpeta obtiene la primera imagen con FACE_FEMALE o FACE_MALE (en ese orden)
     * y genera/cachea un avatar (recorte cuadrado del bbox, redimensionado).
     * Si se pasa $slug y $pdo se usa ese workspace; si no, el actual.
     */
    public function enriquecerCarpetasConAvataresParaWorkspace(string $slug, array $carpetas, PDO $pdo): array
    {
        if (empty($carpetas)) return $carpetas;

        WorkspaceService::ensureStructure($slug);
        $paths = WorkspaceService::paths($slug);
        $imagesDir = $paths['imagesDir'] ?? '';
        $avatarsDir = $paths['avatarsDir'] ?? '';
        if ($imagesDir === '' || $avatarsDir === '') return $carpetas;
        $this->ensureDir($avatarsDir);

        $rutas = [];
        foreach ($carpetas as $c) {
            if (!is_array($c)) continue;
            $r = (string)($c['ruta'] ?? '');
            if ($r !== '') $rutas[] = $r;
        }
        $rutas = array_values(array_unique($rutas));
        if (empty($rutas)) return $carpetas;

        $ph = implode(',', array_fill(0, count($rutas), '?'));
        $tImg = AppConnection::table('images');
        $tDet = AppConnection::table('detections');

        $stmt = $pdo->prepare("
            SELECT i.ruta_carpeta AS ruta, d.image_ruta_relativa AS img, d.label AS label, d.score AS score, d.x1, d.y1, d.x2, d.y2
            FROM {$tImg} i
            JOIN {$tDet} d ON d.workspace_slug = i.workspace_slug AND d.image_ruta_relativa = i.ruta_relativa
            WHERE i.workspace_slug = ? AND i.ruta_carpeta IN ($ph) AND d.label IN ('FACE_FEMALE', 'FACE_MALE', 'FEMALE_BREAST_EXPOSED', 'FEMALE_BREAST_COVERED')
              AND d.x1 IS NOT NULL AND d.y1 IS NOT NULL AND d.x2 IS NOT NULL AND d.y2 IS NOT NULL
            ORDER BY i.ruta_carpeta, d.score DESC, (d.x2 - d.x1) * (d.y2 - d.y1) DESC,
              CASE d.label WHEN 'FACE_FEMALE' THEN 0 WHEN 'FACE_MALE' THEN 1 WHEN 'FEMALE_BREAST_EXPOSED' THEN 2 WHEN 'FEMALE_BREAST_COVERED' THEN 3 ELSE 4 END,
              i.ruta_relativa
        ");
        $stmt->execute(array_merge([$slug], $rutas));
        $rows = $stmt->fetchAll() ?: [];

        $firstByRuta = [];
        foreach ($rows as $r) {
            $ruta = (string)($r['ruta'] ?? '');
            if ($ruta === '' || isset($firstByRuta[$ruta])) continue;
            $x1 = isset($r['x1']) ? (int)$r['x1'] : 0;
            $y1 = isset($r['y1']) ? (int)$r['y1'] : 0;
            $x2 = isset($r['x2']) ? (int)$r['x2'] : 0;
            $y2 = isset($r['y2']) ? (int)$r['y2'] : 0;
            if ($x2 <= $x1 || $y2 <= $y1) continue;
            $score = isset($r['score']) ? (float)$r['score'] : 0.0;
            $firstByRuta[$ruta] = [
                'image_ruta_relativa' => (string)($r['img'] ?? ''),
                'label' => (string)($r['label'] ?? ''),
                'score' => $score,
                'x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2
            ];
        }

        $avatarSize = 64;
        foreach ($carpetas as &$c) {
            $ruta = (string)($c['ruta'] ?? '');
            $c['avatar_url'] = null;
            $face = $firstByRuta[$ruta] ?? null;
            if ($face === null || $face['image_ruta_relativa'] === '') continue;

            $key = sha1($ruta);
            $cachePath = $avatarsDir . DIRECTORY_SEPARATOR . $key . '.jpg';
            $metaPath = $avatarsDir . DIRECTORY_SEPARATOR . $key . '.meta';
            $useCached = false;
            if (is_file($cachePath)) {
                if (is_file($metaPath)) {
                    $metaContent = @file_get_contents($metaPath);
                    if ($metaContent !== false) {
                        $lines = explode("\n", trim($metaContent), 3);
                        $cachedImg = isset($lines[0]) ? trim($lines[0]) : '';
                        $cachedScore = isset($lines[1]) ? trim($lines[1]) : '';
                        $cachedLabel = isset($lines[2]) ? trim($lines[2]) : '';
                        $currentScoreStr = (string)$face['score'];
                        $currentLabel = (string)($face['label'] ?? '');
                        if ($cachedImg === $face['image_ruta_relativa'] && $cachedScore === $currentScoreStr && $cachedLabel === $currentLabel) {
                            $useCached = true;
                        }
                    }
                }
                if ($useCached) {
                    $c['avatar_url'] = '?action=ver_avatar&workspace=' . rawurlencode($slug) . '&k=' . urlencode($key);
                    continue;
                }
                @unlink($cachePath);
                @unlink($metaPath);
            }

            $imagePath = $imagesDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $face['image_ruta_relativa']);
            if (!is_file($imagePath)) continue;

            $mimeIn = null;
            $sizeIn = null;
            $im = $this->loadImageResource($imagePath, $mimeIn, $sizeIn);
            if (!$im) continue;

            $imgW = imagesx($im);
            $imgH = imagesy($im);
            if ($imgW <= 0 || $imgH <= 0) {
                imagedestroy($im);
                continue;
            }

            $x1 = max(0, min($face['x1'], $imgW - 1));
            $y1 = max(0, min($face['y1'], $imgH - 1));
            $x2 = max(0, min($face['x2'], $imgW));
            $y2 = max(0, min($face['y2'], $imgH));
            if ($x2 <= $x1) $x2 = $x1 + 1;
            if ($y2 <= $y1) $y2 = $y1 + 1;

            $w = $x2 - $x1;
            $h = $y2 - $y1;
            $side = max($w, $h);
            $cx = (int)(($x1 + $x2) / 2);
            $cy = (int)(($y1 + $y2) / 2);
            $label = (string)($face['label'] ?? '');
            if ($label === 'FEMALE_BREAST_EXPOSED' || $label === 'FEMALE_BREAST_COVERED') {
                $cy = (int)($cy - $side * 0.5);
            }
            $half = (int)($side / 2);
            $sx1 = $cx - $half;
            $sy1 = $cy - $half;
            $sx2 = $cx + $half;
            $sy2 = $cy + $half;
            if ($sx1 < 0) { $sx2 -= $sx1; $sx1 = 0; }
            if ($sy1 < 0) { $sy2 -= $sy1; $sy1 = 0; }
            if ($sx2 > $imgW) { $sx1 -= ($sx2 - $imgW); $sx2 = $imgW; }
            if ($sy2 > $imgH) { $sy1 -= ($sy2 - $imgH); $sy2 = $imgH; }
            $sx1 = max(0, $sx1);
            $sy1 = max(0, $sy1);
            $sx2 = min($imgW, $sx2);
            $sy2 = min($imgH, $sy2);
            $sw = $sx2 - $sx1;
            $sh = $sy2 - $sy1;
            if ($sw <= 0 || $sh <= 0) {
                imagedestroy($im);
                continue;
            }

            if (!function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled')) {
                imagedestroy($im);
                continue;
            }

            $dst = imagecreatetruecolor($avatarSize, $avatarSize);
            if (!$dst) {
                imagedestroy($im);
                continue;
            }
            imagecopyresampled($dst, $im, 0, 0, $sx1, $sy1, $avatarSize, $avatarSize, $sw, $sh);
            imagedestroy($im);

            $tmpPath = $cachePath . '.tmp';
            $ok = @imagejpeg($dst, $tmpPath, 85);
            imagedestroy($dst);
            if ($ok && is_file($tmpPath)) {
                @rename($tmpPath, $cachePath);
                $metaContent = $face['image_ruta_relativa'] . "\n" . (string)$face['score'] . "\n" . (string)($face['label'] ?? '');
                @file_put_contents($metaPath, $metaContent);
                $c['avatar_url'] = '?action=ver_avatar&workspace=' . rawurlencode($slug) . '&k=' . urlencode($key);
            } else {
                @unlink($tmpPath);
            }
        }
        unset($c);

        return $carpetas;
    }

    /**
     * Enriquecer con avatares usando el workspace actual (para el index).
     */
    private function enriquecerCarpetasConAvatares(array $carpetas): array
    {
        $ws = WorkspaceService::current();
        if ($ws === null || empty($carpetas)) return $carpetas;
        return $this->enriquecerCarpetasConAvataresParaWorkspace($ws, $carpetas, AppConnection::get());
    }

    public function buscar()
    {
        try {
            $busqueda = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
            $carpetasIndex = new CarpetasIndex();

            // Vacío = devolver toda la estructura de carpetas
            if ($busqueda === '') {
                $carpetas = $carpetasIndex->getCarpetas();
                $items = array_map(function ($c) {
                    $nombre = (string)($c['nombre'] ?? '');
                    $ruta = (string)($c['ruta'] ?? '');
                    return [
                        'nombre' => ($nombre !== '' ? $nombre : $ruta),
                        'ruta' => $ruta,
                        'total_archivos' => (int)($c['total_imagenes'] ?? 0)
                    ];
                }, $carpetas);
                $items = $this->enriquecerCarpetasConTags($items);
                usort($items, function ($a, $b) {
                    $ta = (int)($a['total_archivos'] ?? 0);
                    $tb = (int)($b['total_archivos'] ?? 0);
                    if ($tb !== $ta) return $tb <=> $ta;
                    return strcmp((string)($a['ruta'] ?? ''), (string)($b['ruta'] ?? ''));
                });
                $this->jsonResponse([
                    'success' => true,
                    'carpetas' => $items,
                    'total' => count($items),
                    'busqueda' => ''
                ]);
                return;
            }

            $searchKey = StringNormalizer::toSearchKey($busqueda);
            // Un carácter o más: buscar por subcadena
            if ($searchKey === '') {
                $carpetas = $carpetasIndex->getCarpetas();
            } else {
                $carpetas = $carpetasIndex->buscarPorSearchKey($searchKey, 200);
            }

            if (empty($carpetas)) {
                $this->jsonResponse([
                    'success' => true,
                    'carpetas' => [],
                    'total' => 0,
                    'busqueda' => $busqueda
                ]);
                return;
            }

            $items = array_map(function ($c) {
                $nombre = (string)($c['nombre'] ?? '');
                $ruta = (string)($c['ruta'] ?? '');
                return [
                    'nombre' => ($nombre !== '' ? $nombre : $ruta),
                    'ruta' => $ruta,
                    'total_archivos' => (int)($c['total_imagenes'] ?? 0)
                ];
            }, $carpetas);
            $items = $this->enriquecerCarpetasConTags($items);
            usort($items, function ($a, $b) {
                $ta = (int)($a['total_archivos'] ?? 0);
                $tb = (int)($b['total_archivos'] ?? 0);
                if ($tb !== $ta) return $tb <=> $ta;
                return strcmp((string)($a['ruta'] ?? ''), (string)($b['ruta'] ?? ''));
            });

            $this->jsonResponse([
                'success' => true,
                'carpetas' => $items,
                'total' => count($items),
                'busqueda' => $busqueda
            ]);
            
        } catch (\Exception $e) {
            $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    public function ver()
    {
        try {
            $ws = WorkspaceService::current();
            if ($ws === null) {
                $this->jsonResponse(['success' => false, 'error' => 'No hay workspace activo'], 409);
            }
            WorkspaceService::ensureStructure($ws);
            $directorioBase = WorkspaceService::paths($ws)['imagesDir'];
            
            $ruta = $_GET['ruta'] ?? '';
            
            if (empty($ruta)) {
                $this->jsonResponse([
                    'success' => false,
                    'error' => 'Ruta requerida'
                ], 400);
            }
            
            // Validar que la ruta no contiene caracteres peligrosos
            if (preg_match('/\.\./', $ruta)) {
                $this->jsonResponse([
                    'success' => false,
                    'error' => 'Ruta inválida'
                ], 400);
            }
            
            $rutaCompleta = $directorioBase . '/' . $ruta;
            
            // Verificar que el directorio existe
            if (!is_dir($rutaCompleta)) {
                $this->jsonResponse([
                    'success' => false,
                    'error' => 'La carpeta no existe'
                ], 404);
            }
            
            // --- Lookup estado + tags por imagen de esta carpeta (2 consultas sin JOIN) ---
            $pdo = AppConnection::get();
            AppSchema::ensure($pdo);
            $tImg = AppConnection::table('images');
            $tDet = AppConnection::table('detections');

            $rutaNorm = str_replace('\\', '/', (string)$ruta);
            $rutaNorm = trim($rutaNorm, '/');

            $stmtImg = $pdo->prepare("
                SELECT ruta_relativa, archivo, clasif_estado, detect_estado
                FROM {$tImg}
                WHERE workspace_slug = :ws AND COALESCE(ruta_carpeta,'') = :rc
            ");
            $stmtImg->execute([':ws' => $ws, ':rc' => $rutaNorm]);
            $imgRows = $stmtImg->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $byArchivo = [];
            $rutaRelativas = [];
            foreach ($imgRows as $r) {
                $archivo = (string)($r['archivo'] ?? '');
                if ($archivo === '') continue;
                $rrel = (string)($r['ruta_relativa'] ?? '');
                $clas = (string)($r['clasif_estado'] ?? '');
                $det = (string)($r['detect_estado'] ?? '');
                $pend = ($det !== 'ok') || ($clas === 'pending');
                $byArchivo[$archivo] = [
                    'ruta_relativa' => $rrel,
                    'pendiente' => $pend,
                    'tagsMap' => []
                ];
                if ($rrel !== '') $rutaRelativas[] = $rrel;
            }

            if (!empty($rutaRelativas)) {
                $ph = implode(',', array_fill(0, count($rutaRelativas), '?'));
                $stmtDet = $pdo->prepare("
                    SELECT image_ruta_relativa, label
                    FROM {$tDet}
                    WHERE workspace_slug = ? AND image_ruta_relativa IN ($ph)
                ");
                $stmtDet->execute(array_merge([$ws], $rutaRelativas));
                $detRows = $stmtDet->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $rutaToArchivo = [];
                foreach ($byArchivo as $arch => $data) {
                    $rutaToArchivo[(string)$data['ruta_relativa']] = $arch;
                }
                foreach ($detRows as $dr) {
                    $rrel = (string)($dr['image_ruta_relativa'] ?? '');
                    $lab = isset($dr['label']) ? strtoupper(trim((string)$dr['label'])) : '';
                    if ($lab === '' || $rrel === '') continue;
                    $arch = $rutaToArchivo[$rrel] ?? null;
                    if ($arch !== null) {
                        $byArchivo[$arch]['tagsMap'][$lab] = true;
                    }
                }
            }

            // Obtener archivos de la carpeta
            $archivos = scandir($rutaCompleta);
            $archivosInfo = [];
            
            $extensionesImagen = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'avif', 'heic', 'heif', 'ico', 'svg'];
            
            foreach ($archivos as $archivo) {
                if ($archivo === '.' || $archivo === '..') {
                    continue;
                }
                
                $rutaArchivo = $rutaCompleta . '/' . $archivo;
                
                // Solo procesar archivos
                if (!is_file($rutaArchivo)) {
                    continue;
                }
                
                $extension = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
                $esImagen = in_array($extension, $extensionesImagen);
                
                $archivosInfo[] = [
                    'nombre' => $archivo,
                    'es_imagen' => $esImagen,
                    'extension' => $extension,
                    'tamano' => filesize($rutaArchivo),
                    // Metadata de SQLite (si existe)
                    'ruta_relativa' => $byArchivo[$archivo]['ruta_relativa'] ?? (($rutaNorm !== '' ? ($rutaNorm . '/' . $archivo) : $archivo)),
                    'pendiente' => (bool)($byArchivo[$archivo]['pendiente'] ?? true),
                    'tags' => array_keys(($byArchivo[$archivo]['tagsMap'] ?? []))
                ];
            }
            
            // Ordenar por nombre
            usort($archivosInfo, function($a, $b) {
                return strnatcasecmp($a['nombre'], $b['nombre']);
            });
            
            $this->jsonResponse([
                'success' => true,
                'archivos' => $archivosInfo,
                'total' => count($archivosInfo)
            ]);
            
        } catch (\Exception $e) {
            $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    public function verImagen()
    {
        try {
            $ws = WorkspaceService::current();
            if ($ws === null) {
                http_response_code(409);
                die('No hay workspace activo');
            }
            WorkspaceService::ensureStructure($ws);
            $directorioBase = WorkspaceService::paths($ws)['imagesDir'];
            
            $ruta = $_GET['ruta'] ?? '';
            $archivo = $_GET['archivo'] ?? '';
            
            if (empty($ruta) || empty($archivo)) {
                http_response_code(400);
                die('Ruta y archivo requeridos');
            }
            
            // Validar que la ruta no contiene caracteres peligrosos
            if (preg_match('/\.\./', $ruta) || preg_match('/\.\./', $archivo)) {
                http_response_code(400);
                die('Ruta inválida');
            }
            
            $rutaCompleta = $directorioBase . '/' . $ruta . '/' . $archivo;
            
            // Verificar que el archivo existe
            if (!file_exists($rutaCompleta) || !is_file($rutaCompleta)) {
                http_response_code(404);
                die('Archivo no encontrado');
            }

            // --- Thumbnails cacheados (para grid del modal) ---
            $thumb = ($_GET['thumb'] ?? null);
            $thumb = ($thumb === '1' || $thumb === 1 || $thumb === true || $thumb === 'true');
            if ($thumb) {
                $w = (int)($_GET['w'] ?? 120);
                $w = max(40, min(400, $w));

                // Cache key basado en path+mtime+size+w+version
                $real = realpath($rutaCompleta) ?: $rutaCompleta;
                $st = @stat($real) ?: null;
                $mtime = is_array($st) ? (int)($st['mtime'] ?? 0) : 0;
                $size = is_array($st) ? (int)($st['size'] ?? 0) : 0;
                $key = sha1($real . '|' . $mtime . '|' . $size . '|' . $w . '|thumb_v1');

                $paths = WorkspaceService::paths($ws);
                $thumbsDir = $paths['thumbsDir'] ?? $this->workspacePath('thumbs');
                $cacheDirParent = $paths['cacheDir'] ?? dirname($thumbsDir);
                $cacheDir = $thumbsDir;
                $this->ensureDir($cacheDirParent);
                $this->ensureDir($cacheDir);
                if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
                    \App\Services\WorkspaceService::ensureStructure($ws);
                    clearstatcache(true, $cacheDir);
                }
                $cacheDirResolved = realpath($cacheDir);
                if ($cacheDirResolved === false) {
                    $cacheDirResolved = rtrim($cacheDir, "/\\");
                }

                $useWebp = function_exists('imagewebp');
                $extOut = $useWebp ? 'webp' : 'jpg';
                $contentType = $useWebp ? 'image/webp' : 'image/jpeg';
                $cachePath = $cacheDirResolved . DIRECTORY_SEPARATOR . $key . '.' . $extOut;
                $forceRegenerate = isset($_GET['force']) && ($_GET['force'] === '1' || $_GET['force'] === 1);
                $isHead = (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD');

                if (!$forceRegenerate && is_file($cachePath)) {
                    if (filesize($cachePath) > 0) {
                        header('X-Thumb-Workspace: ' . $ws);
                        if ($isHead) {
                            header('HTTP/1.1 204 No Content');
                            header('X-Thumb-Cached: 1');
                            header('Access-Control-Expose-Headers: X-Thumb-New, X-Thumb-Cached');
                            return;
                        }
                        $this->outputFile($cachePath, $contentType, true, true);
                        return;
                    }
                    @unlink($cachePath);
                }

                $lockPath = $cachePath . '.lock';
                $lockFp = @fopen($lockPath, 'c');
                if ($lockFp && flock($lockFp, LOCK_EX)) {
                    clearstatcache(true, $cachePath);
                    if (!$forceRegenerate && is_file($cachePath) && filesize($cachePath) > 0) {
                        flock($lockFp, LOCK_UN);
                        fclose($lockFp);
                        @unlink($lockPath);
                        header('X-Thumb-Workspace: ' . $ws);
                        if ($isHead) {
                            header('HTTP/1.1 204 No Content');
                            header('X-Thumb-Cached: 1');
                            header('Access-Control-Expose-Headers: X-Thumb-New, X-Thumb-Cached');
                            return;
                        }
                        $this->outputFile($cachePath, $contentType, true, true);
                        return;
                    }
                }
                $releaseLock = function () use (&$lockFp, $lockPath) {
                    if ($lockFp) {
                        @flock($lockFp, LOCK_UN);
                        @fclose($lockFp);
                        @unlink($lockPath);
                        $lockFp = null;
                    }
                };

                // Generar thumb (si GD no está disponible, devolver original como fallback)
                if (!function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled')) {
                    // fallback: original
                    // Nota: no cacheamos en este fallback.
                    // Continuar flujo normal (más abajo) para servir el original.
                } else {
                    $mimeIn = null;
                    $sizeIn = null;
                    $im = $this->loadImageResource($rutaCompleta, $mimeIn, $sizeIn);
                    if ($im) {
                        $srcW = imagesx($im);
                        $srcH = imagesy($im);
                        if ($srcW > 0 && $srcH > 0) {
                            $maxSide = max($srcW, $srcH);
                            $scale = ($maxSide > $w) ? ($w / $maxSide) : 1.0; // no upscaling
                            $dstW = max(1, (int)round($srcW * $scale));
                            $dstH = max(1, (int)round($srcH * $scale));

                            $dst = imagecreatetruecolor($dstW, $dstH);

                            // Preservar alpha en PNG/WEBP/GIF
                            $isAlpha = false;
                            $ext = strtolower(pathinfo($rutaCompleta, PATHINFO_EXTENSION));
                            if ($mimeIn && (stripos($mimeIn, 'png') !== false || stripos($mimeIn, 'webp') !== false || stripos($mimeIn, 'gif') !== false)) $isAlpha = true;
                            if (in_array($ext, ['png', 'webp', 'gif'], true)) $isAlpha = true;
                            if ($isAlpha) {
                                imagealphablending($dst, false);
                                imagesavealpha($dst, true);
                                $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
                                imagefilledrectangle($dst, 0, 0, $dstW - 1, $dstH - 1, $transparent);
                            }

                            imagecopyresampled($dst, $im, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

                            $tmpPath = $cachePath . '.tmp';
                            $thumbData = null;
                            $memStream = @fopen('php://temp', 'r+');
                            if ($memStream !== false) {
                                $okWrite = false;
                                if ($useWebp) {
                                    $okWrite = @imagewebp($dst, $memStream, 70);
                                } else {
                                    if ($isAlpha) {
                                        $dst2 = imagecreatetruecolor($dstW, $dstH);
                                        $white = imagecolorallocate($dst2, 255, 255, 255);
                                        imagefilledrectangle($dst2, 0, 0, $dstW - 1, $dstH - 1, $white);
                                        imagecopy($dst2, $dst, 0, 0, 0, 0, $dstW, $dstH);
                                        imagedestroy($dst);
                                        $dst = $dst2;
                                    }
                                    $okWrite = @imagejpeg($dst, $memStream, 70);
                                }
                                imagedestroy($im);
                                imagedestroy($dst);
                                if ($okWrite) {
                                    rewind($memStream);
                                    $thumbData = stream_get_contents($memStream);
                                }
                                fclose($memStream);
                            } else {
                                imagedestroy($im);
                                if (isset($dst)) imagedestroy($dst);
                            }

                            if ($thumbData !== null && strlen($thumbData) > 0) {
                                @file_put_contents($cachePath, $thumbData, LOCK_EX);
                                $releaseLock();
                                if ($isHead) {
                                    header('HTTP/1.1 204 No Content');
                                    header('X-Thumb-New: 1');
                                    header('Access-Control-Expose-Headers: X-Thumb-New, X-Thumb-Cached');
                                    return;
                                }
                                while (ob_get_level()) {
                                    ob_end_clean();
                                }
                                header('X-Thumb-Workspace: ' . $ws);
                                header('Content-Type: ' . $contentType);
                                header('Content-Length: ' . strlen($thumbData));
                                header('Cache-Control: public, max-age=31536000, immutable');
                                header('X-Thumb-New: 1');
                                header('Access-Control-Expose-Headers: X-Thumb-New, X-Thumb-Cached');
                                echo $thumbData;
                                exit;
                            }
                            $releaseLock();
                            header('X-Thumb-Debug: generate-failed');
                            $mimeFallback = $mimeIn ?? (pathinfo($archivo, PATHINFO_EXTENSION) === 'png' ? 'image/png' : 'image/jpeg');
                            $this->outputFile($rutaCompleta, $mimeFallback, false);
                            return;
                        } else {
                            imagedestroy($im);
                        }
                    }
                    $releaseLock();
                }
                $releaseLock();
            }

            // Obtener el tipo MIME
            $mimeType = null;
            if (function_exists('finfo_open')) {
                $finfo = @finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $mimeType = @finfo_file($finfo, $rutaCompleta);
                    @finfo_close($finfo);
                }
            }
            if (!$mimeType) {
                $extension = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
                $mimeTypes = [
                    'jpg' => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'gif' => 'image/gif',
                    'webp' => 'image/webp',
                    'bmp' => 'image/bmp',
                    'tif' => 'image/tiff',
                    'tiff' => 'image/tiff',
                    'avif' => 'image/avif',
                    'heic' => 'image/heic',
                    'heif' => 'image/heif',
                    'ico' => 'image/x-icon',
                    'svg' => 'image/svg+xml'
                ];
                $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';
            }
            
            // Enviar el archivo
            $this->outputFile($rutaCompleta, $mimeType ?: 'application/octet-stream', false);
            
        } catch (\Exception $e) {
            http_response_code(500);
            die('Error: ' . $e->getMessage());
        }
    }

    /**
     * Sirve un avatar cacheado (cache/avatars/{k}.jpg).
     * Si se pasa ?workspace=slug se usa ese workspace; si no, el actual.
     */
    public function verAvatar(): void
    {
        $k = isset($_GET['k']) ? trim((string)$_GET['k']) : '';
        if ($k === '' || !preg_match('/^[a-f0-9]{40}$/', $k)) {
            http_response_code(404);
            die('Avatar no encontrado');
        }
        $ws = isset($_GET['workspace']) ? trim((string)$_GET['workspace']) : null;
        if ($ws !== null && $ws !== '') {
            if (!WorkspaceService::isValidSlug($ws) || !WorkspaceService::exists($ws)) {
                http_response_code(404);
                die('Avatar no encontrado');
            }
        } else {
            $ws = WorkspaceService::current();
        }
        if ($ws === null || $ws === '') {
            http_response_code(404);
            die('Avatar no encontrado');
        }
        $paths = WorkspaceService::paths($ws);
        $avatarsDir = $paths['avatarsDir'] ?? '';
        if ($avatarsDir === '') {
            http_response_code(404);
            die('Avatar no encontrado');
        }
        $path = $avatarsDir . DIRECTORY_SEPARATOR . $k . '.jpg';
        if (!is_file($path)) {
            http_response_code(404);
            die('Avatar no encontrado');
        }
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}
