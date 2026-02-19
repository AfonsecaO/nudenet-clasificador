<?php

namespace App\Controllers;

use App\Models\ImagenesIndex;
use App\Services\HeicConverter;
use App\Services\LogService;
use App\Services\AppConnection;
use App\Services\AppSchema;
use App\Services\WorkspaceService;

class UploadController extends BaseController
{
    private function parseIniBytes($v): int
    {
        $s = trim((string)$v);
        if ($s === '') return 0;
        if (is_numeric($s)) return (int)$s;
        $num = (float)preg_replace('/[^0-9.]+/', '', $s);
        $unit = strtolower((string)preg_replace('/[0-9.\s]+/', '', $s));
        $mul = 1;
        if ($unit === 'k' || $unit === 'kb') $mul = 1024;
        else if ($unit === 'm' || $unit === 'mb') $mul = 1024 * 1024;
        else if ($unit === 'g' || $unit === 'gb') $mul = 1024 * 1024 * 1024;
        return (int)round($num * $mul);
    }

    private function isValidImage(string $tmpPath, string $origName = ''): bool
    {
        if (!is_file($tmpPath)) return false;

        // 1) MIME (más confiable para tmp files sin extensión)
        $mime = null;
        if (function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $m = @finfo_file($fi, $tmpPath);
                @finfo_close($fi);
                if (is_string($m) && $m !== '') $mime = $m;
            }
        }
        if ($mime === null && function_exists('mime_content_type')) {
            $m = @mime_content_type($tmpPath);
            if (is_string($m) && $m !== '') $mime = $m;
        }
        if (is_string($mime) && stripos($mime, 'image/') === 0) {
            return true;
        }

        // 2) Header (getimagesize) para formatos comunes (jpg/png/gif/webp/bmp/etc.)
        if (function_exists('getimagesize')) {
            $info = @getimagesize($tmpPath);
            if ($info !== false) return true;
        }

        // 3) Fallback por extensión del nombre original (no del tmp)
        $extOk = ['jpg'=>true,'jpeg'=>true,'png'=>true,'gif'=>true,'webp'=>true,'bmp'=>true,'tif'=>true,'tiff'=>true,'avif'=>true,'heic'=>true,'heif'=>true,'ico'=>true,'svg'=>true];
        $ext = strtolower(pathinfo((string)$origName, PATHINFO_EXTENSION));
        if ($ext !== '' && isset($extOk[$ext])) return true;

        return false;
    }

    private function sanitizeRelativePath(string $name): ?string
    {
        $s = str_replace('\\', '/', trim($name));
        $s = ltrim($s, '/');
        if ($s === '') return null;
        // prevenir traversal / rutas absolutas
        if (preg_match('/\.\./', $s)) return null;
        if (preg_match('/^[A-Za-z]:\//', $s)) return null;

        // Normalizar separadores múltiples
        $s = preg_replace('#/+#', '/', $s);
        $s = trim((string)$s, '/');
        if ($s === '') return null;
        return $s;
    }

    private function uniqueDestPath(string $destPath): string
    {
        if (!file_exists($destPath)) return $destPath;
        $dir = dirname($destPath);
        $base = pathinfo($destPath, PATHINFO_FILENAME);
        $ext = pathinfo($destPath, PATHINFO_EXTENSION);
        $i = 1;
        while (true) {
            $cand = $dir . DIRECTORY_SEPARATOR . $base . '_' . $i . ($ext ? ('.' . $ext) : '');
            if (!file_exists($cand)) return $cand;
            $i++;
            if ($i > 9999) return $destPath;
        }
    }

    public function uploadImagenes()
    {
        try {
            $ws = WorkspaceService::current();
            if ($ws === null) {
                $this->jsonResponse(['success' => false, 'needs_workspace' => true], 409);
            }
            WorkspaceService::ensureStructure($ws);
            $imagesDir = WorkspaceService::paths($ws)['imagesDir'];

            // Si el POST excede límites (post_max_size), PHP puede dejar $_FILES vacío.
            // Intentar dar un mensaje más claro.
            $contentLen = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
            $postMax = $this->parseIniBytes(@ini_get('post_max_size'));
            if ($contentLen > 0 && $postMax > 0 && $contentLen > $postMax && empty($_FILES)) {
                $this->jsonResponse([
                    'success' => false,
                    'error' => 'La carga excede post_max_size del servidor. Sube por lotes más pequeños.',
                    'limits' => [
                        'post_max_size' => (string)@ini_get('post_max_size'),
                        'upload_max_filesize' => (string)@ini_get('upload_max_filesize'),
                        'max_file_uploads' => (string)@ini_get('max_file_uploads'),
                    ]
                ], 413);
            }

            if (empty($_FILES['files'])) {
                $this->jsonResponse(['success' => false, 'error' => 'No se recibieron archivos (field: files)'], 400);
            }

            $f = $_FILES['files'];
            $names = $f['name'] ?? [];
            $tmps = $f['tmp_name'] ?? [];
            $errs = $f['error'] ?? [];
            // PHP 8.1+: full_path conserva la ruta; si no, usamos paths[] enviado por el frontend (PHP quita la ruta de 'name')
            $fullPaths = $f['full_path'] ?? [];
            $pathsFromPost = $_POST['paths'] ?? [];
            if (!is_array($pathsFromPost)) $pathsFromPost = [];

            if (!is_array($names)) $names = [$names];
            if (!is_array($tmps)) $tmps = [$tmps];
            if (!is_array($errs)) $errs = [$errs];
            if (!is_array($fullPaths)) $fullPaths = [$fullPaths];

            $n = min(count($names), count($tmps), count($errs));
            if ($n <= 0) {
                $this->jsonResponse(['success' => false, 'error' => 'No se recibieron archivos válidos'], 400);
            }

            $pdo = AppConnection::get();
            AppSchema::ensure($pdo);
            $tImg = AppConnection::table('images');
            $stmtMd5 = $pdo->prepare("SELECT ruta_relativa FROM {$tImg} WHERE content_md5 = :m LIMIT 1");

            $savedPaths = [];

            $out = [
                'success' => true,
                'workspace' => $ws,
                'total' => $n,
                'uploaded' => 0,
                'skipped_md5' => 0,
                'skipped_invalid' => 0,
                'renamed' => 0,
                'errors' => 0,
                'items' => [],
            ];

            // Solo se guardan imágenes; el resto se descarta. Rutas recursivas (subcarpetas) se crean con mkdir(..., true).
            for ($i = 0; $i < $n; $i++) {
                $origName = is_string($names[$i]) ? $names[$i] : '';
                $tmpName = is_string($tmps[$i]) ? $tmps[$i] : '';
                $err = $errs[$i] ?? UPLOAD_ERR_NO_FILE;
                $pathForRel = $origName;
                if (isset($fullPaths[$i]) && is_string($fullPaths[$i]) && $fullPaths[$i] !== '') {
                    $pathForRel = $fullPaths[$i];
                } elseif (isset($pathsFromPost[$i]) && is_string($pathsFromPost[$i]) && $pathsFromPost[$i] !== '') {
                    $pathForRel = $pathsFromPost[$i];
                }

                if ($err !== UPLOAD_ERR_OK || $tmpName === '' || !is_file($tmpName)) {
                    $out['errors']++;
                    continue;
                }

                $rel = $this->sanitizeRelativePath($pathForRel);
                if ($rel === null) {
                    $out['skipped_invalid']++;
                    continue;
                }

                // Descartar no-imágenes (videos, documentos, etc.); solo se suben imágenes
                if (!$this->isValidImage($tmpName, $origName)) {
                    $out['skipped_invalid']++;
                    continue;
                }

                $md5 = @md5_file($tmpName);
                if (!is_string($md5) || strlen($md5) !== 32) {
                    $out['errors']++;
                    continue;
                }
                $md5 = strtolower($md5);

                // Dedupe por MD5
                $stmtMd5->execute([':m' => $md5]);
                $existsKey = $stmtMd5->fetchColumn();
                if ($existsKey !== false) {
                    $out['skipped_md5']++;
                    continue;
                }

                $dest = rtrim($imagesDir, "/\\") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                $destDir = dirname($dest);
                // Crear subcarpetas recursivas (ej.: Carpeta/Subcarpeta/nested/) según la ruta relativa
                if (!is_dir($destDir)) {
                    @mkdir($destDir, 0755, true);
                }
                if (!is_dir($destDir)) {
                    $out['errors']++;
                    continue;
                }

                $dest2 = $dest;
                $extDest = strtolower(pathinfo($dest2, PATHINFO_EXTENSION));
                $isHeic = ($extDest === 'heic' || $extDest === 'heif');

                if ($isHeic) {
                    $jpgBinary = HeicConverter::convertFileToJpgBinary($tmpName);
                    if ($jpgBinary !== null) {
                        $dest2 = preg_replace('/\.(heic|heif)$/i', '.jpg', $dest2);
                        if (file_exists($dest2)) {
                            $dest2 = $this->uniqueDestPath($dest2);
                            $out['renamed']++;
                        }
                        $ok = @file_put_contents($dest2, $jpgBinary) !== false;
                        @unlink($tmpName);
                        if (!$ok || !is_file($dest2)) {
                            $out['errors']++;
                            continue;
                        }
                        $savedPaths[] = $dest2;
                        $out['uploaded']++;
                        continue;
                    }
                    // Conversión fallida: guardar HEIC como está (o skip; guardamos como está)
                }

                if (file_exists($dest2)) {
                    $dest2 = $this->uniqueDestPath($dest2);
                    $out['renamed']++;
                }

                // Move file
                $ok = @move_uploaded_file($tmpName, $dest2);
                if (!$ok) {
                    $ok = @rename($tmpName, $dest2);
                }
                if (!$ok || !is_file($dest2)) {
                    $out['errors']++;
                    continue;
                }

                $savedPaths[] = $dest2;
                $out['uploaded']++;
            }

            // Indexar lo subido
            if (!empty($savedPaths)) {
                $idx = new ImagenesIndex();
                $delta = $idx->upsertDesdeRutas($savedPaths, $imagesDir);
                $out['indexed'] = $delta;
            } else {
                $out['indexed'] = ['nuevas' => 0, 'existentes' => 0, 'ignoradas' => 0];
            }

            $uploaded = (int)($out['uploaded'] ?? 0);
            $skipped = (int)($out['skipped_md5'] ?? 0) + (int)($out['skipped_invalid'] ?? 0);
            LogService::append([
                'type' => 'success',
                'message' => 'Subida: ' . $uploaded . ' archivos subidos' . ($skipped > 0 ? ', ' . $skipped . ' omitidos' : '') . '.',
            ]);

            $this->jsonResponse($out);
        } catch (\Throwable $e) {
            $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}

