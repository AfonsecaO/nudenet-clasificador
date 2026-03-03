<?php

namespace App\Controllers;

use App\Models\CarpetasIndex;
use App\Models\ImageModerationLabels;
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

    /**
     * Valida que la ruta objetivo esté bajo el directorio base (evita path traversal y symlinks).
     * Devuelve true si es segura; si no, establece el código HTTP apropiado y devuelve false.
     */
    private function ensurePathUnderBase(string $targetPath, string $basePath): bool
    {
        $baseReal = realpath($basePath);
        $targetReal = realpath($targetPath);
        if ($baseReal === false || $targetReal === false) {
            http_response_code(404);
            return false;
        }
        $baseNorm = rtrim(str_replace('\\', '/', $baseReal), '/');
        $targetNorm = str_replace('\\', '/', $targetReal);
        if ($targetNorm !== $baseNorm && strpos($targetNorm, $baseNorm . '/') !== 0) {
            http_response_code(403);
            return false;
        }
        return true;
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

    /** Tamaño de chunk para IN (folder_path) y evitar límite de placeholders. */
    private const ENRIQUECER_TAGS_CHUNK_SIZE = 100;

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
        $ws = WorkspaceService::current() ?? '';

        $totalByRuta = [];
        $chunks = array_chunk($rutas, self::ENRIQUECER_TAGS_CHUNK_SIZE);
        foreach ($chunks as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $stmtImg = $pdo->prepare("
                SELECT relative_path, folder_path
                FROM {$tImg}
                WHERE workspace_slug = ? AND COALESCE(folder_path,'') IN ($ph)
            ");
            $stmtImg->execute(array_merge([$ws], $chunk));
            $imgRows = $stmtImg->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($imgRows as $r) {
                $rc = (string)($r['folder_path'] ?? '');
                if ($rc !== '') {
                    $totalByRuta[$rc] = ($totalByRuta[$rc] ?? 0) + 1;
                }
            }
        }

        $out = [];
        foreach ($carpetas as $c) {
            if (!is_array($c)) continue;
            $ruta = (string)($c['ruta'] ?? '');
            $c['pendientes'] = 0;
            $totalReal = (int)($totalByRuta[$ruta] ?? $c['total_archivos'] ?? $c['total_imagenes'] ?? 0);
            if (array_key_exists('total_archivos', $c)) {
                $c['total_archivos'] = $totalReal;
            }
            if (array_key_exists('total_imagenes', $c)) {
                $c['total_imagenes'] = $totalReal;
            }
            $c['tags'] = [];
            $out[] = $c;
        }

        $out = $this->enriquecerCarpetasConAvatares($out);
        return $out;
    }

    /**
     * Sin clasificación/detecciones ya no se generan avatares por cara; se deja avatar_url en null.
     */
    public function enriquecerCarpetasConAvataresParaWorkspace(string $slug, array $carpetas, PDO $pdo): array
    {
        if (empty($carpetas)) return $carpetas;
        foreach ($carpetas as &$c) {
            $c['avatar_url'] = null;
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
            if (isset($_GET['workspace']) && trim((string)$_GET['workspace']) !== '') {
                $reqWs = WorkspaceService::slugify(trim((string)$_GET['workspace']));
                if ($reqWs !== '' && WorkspaceService::isValidSlug($reqWs) && WorkspaceService::exists($reqWs)) {
                    WorkspaceService::setCurrent($reqWs);
                }
            }
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

            if (!$this->ensurePathUnderBase($rutaCompleta, $directorioBase)) {
                $this->jsonResponse(['success' => false, 'error' => 'Ruta inválida'], 403);
            }
            
            // Verificar que el directorio existe
            if (!is_dir($rutaCompleta)) {
                $this->jsonResponse([
                    'success' => false,
                    'error' => 'La carpeta no existe'
                ], 404);
            }
            
            $pdo = AppConnection::get();
            AppSchema::ensure($pdo);
            $tImg = AppConnection::table('images');

            $rutaNorm = str_replace('\\', '/', (string)$ruta);
            $rutaNorm = trim($rutaNorm, '/');

            $stmtImg = $pdo->prepare("
                SELECT relative_path, filename, full_path
                FROM {$tImg}
                WHERE workspace_slug = :ws AND COALESCE(folder_path,'') = :rc
            ");
            $stmtImg->execute([':ws' => $ws, ':rc' => $rutaNorm]);
            $imgRows = $stmtImg->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $labelsByPath = [];
            if (!empty($imgRows)) {
                $modelLabels = new ImageModerationLabels();
                foreach ($imgRows as $r) {
                    $rrel = (string)($r['relative_path'] ?? '');
                    if ($rrel !== '') {
                        $labelsByPath[$rrel] = $modelLabels->getForImage($ws, $rrel);
                    }
                }
            }

            $archivosInfo = [];
            $extensionesImagen = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'avif', 'heic', 'heif', 'ico', 'svg'];

            foreach ($imgRows as $r) {
                $archivo = (string)($r['filename'] ?? '');
                if ($archivo === '') continue;
                $rrel = (string)($r['relative_path'] ?? '');
                $rutaCompletaArchivo = $r['full_path'] ?? null;
                $tamano = 0;
                if ($rutaCompletaArchivo !== null && $rutaCompletaArchivo !== '' && is_file($rutaCompletaArchivo)) {
                    $tamano = (int)@filesize($rutaCompletaArchivo);
                }
                $extension = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
                $esImagen = in_array($extension, $extensionesImagen);
                $moderationLabels = $labelsByPath[$rrel] ?? [];

                $archivosInfo[] = [
                    'nombre' => $archivo,
                    'es_imagen' => $esImagen,
                    'extension' => $extension,
                    'tamano' => $tamano,
                    'ruta_relativa' => $rrel,
                    'pendiente' => false,
                    'tags' => [],
                    'moderation_labels' => $moderationLabels
                ];
            }

            usort($archivosInfo, function ($a, $b) {
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

            if (!$this->ensurePathUnderBase($rutaCompleta, $directorioBase)) {
                http_response_code(403);
                die('Ruta inválida');
            }
            
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

                // Cache key: path+mtime+size+w+content_md5 (si está en índice) para invalidar solo cuando cambie contenido
                $real = realpath($rutaCompleta) ?: $rutaCompleta;
                $st = @stat($real) ?: null;
                $mtime = is_array($st) ? (int)($st['mtime'] ?? 0) : 0;
                $size = is_array($st) ? (int)($st['size'] ?? 0) : 0;
                $contentMd5 = null;
                try {
                    $rutaRelativa = str_replace('\\', '/', trim($ruta, "/\\") . '/' . trim($archivo, "/\\"));
                    $rutaRelativa = preg_replace('#/+#', '/', $rutaRelativa);
                    $pdoThumb = AppConnection::get();
                    AppSchema::ensure($pdoThumb);
                    $tImg = AppConnection::table('images');
                    $wsThumb = \App\Services\WorkspaceService::current() ?? '';
                    $stmtMd5 = $pdoThumb->prepare("SELECT content_md5 FROM {$tImg} WHERE workspace_slug = :ws AND relative_path = :k LIMIT 1");
                    $stmtMd5->execute([':ws' => $wsThumb, ':k' => $rutaRelativa]);
                    $row = $stmtMd5->fetch(\PDO::FETCH_ASSOC);
                    if (!empty($row['content_md5']) && strlen($row['content_md5']) === 32) {
                        $contentMd5 = $row['content_md5'];
                    }
                } catch (\Throwable $e) {
                    // Ignorar: seguir con key sin content_md5
                }
                $key = sha1($real . '|' . $mtime . '|' . $size . '|' . $w . '|' . ($contentMd5 ?? '') . '|thumb_v1');

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

                // HEAD sin caché: no generar; solo GET genera el thumb
                if ($isHead) {
                    $releaseLock();
                    header('HTTP/1.1 204 No Content');
                    header('Access-Control-Expose-Headers: X-Thumb-New, X-Thumb-Cached');
                    return;
                }

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
        if (!$this->ensurePathUnderBase($path, $avatarsDir)) {
            http_response_code(403);
            die('Ruta inválida');
        }
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}
