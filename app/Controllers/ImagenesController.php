<?php

namespace App\Controllers;

use App\Models\ImagenesIndex;
use App\Models\CarpetasIndex;
use App\Services\LogService;
use App\Services\AppConnection;
use App\Services\AppSchema;
use App\Services\WorkspaceService;

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

                $pdo = AppConnection::get();
                AppSchema::ensure($pdo);
                $tImg = AppConnection::table('images');
                $wsDedupe = AppConnection::currentSlug() ?? 'default';

                $stmtRows = $pdo->prepare("
                    SELECT relative_path, full_path, file_mtime
                    FROM {$tImg}
                    WHERE workspace_slug = :ws
                    ORDER BY COALESCE(file_mtime, 0) ASC, relative_path ASC
                ");
                $stmtRows->execute([':ws' => $wsDedupe]);
                $rows = $stmtRows->fetchAll();

                $seen = [];
                $pdo->beginTransaction();
                try {
                    $stmtSet = $pdo->prepare("UPDATE {$tImg} SET content_md5 = :m WHERE workspace_slug = :ws AND relative_path = :k");
                    $stmtDel = $pdo->prepare("DELETE FROM {$tImg} WHERE workspace_slug = :ws AND relative_path = :k");

                    foreach ($rows as $r) {
                        $k = (string)($r['relative_path'] ?? '');
                        $full = (string)($r['full_path'] ?? '');
                        if ($k === '' || $full === '') continue;
                        if (!is_file($full)) continue;

                        $md5 = @md5_file($full);
                        if (!is_string($md5) || strlen($md5) !== 32) continue;
                        $md5 = strtolower($md5);

                        if (!isset($seen[$md5])) {
                            // keep
                            $seen[$md5] = $k;
                            try {
                                $stmtSet->execute([':m' => $md5, ':ws' => $wsDedupe, ':k' => $k]);
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

                        $stmtDel->execute([':ws' => $wsDedupe, ':k' => $k]);
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
                'elapsed_ms' => $elapsedMs
            ]);
        } catch (\Exception $e) {
            $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

