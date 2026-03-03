<?php

namespace App\Models;

use App\Services\HeicConverter;
use App\Services\ImageCompressor;
use App\Services\StringNormalizer;

/**
 * Índice persistente de imágenes en SQLite (tmp/db/clasificador.sqlite)
 * - Sincroniza con el filesystem sin perder resultados ya procesados.
 * - Permite seleccionar pendientes y buscar usando solo el índice.
 */
class ImagenesIndex
{
    private $pdo;
    private $tImages;
    private $tFolders;
    private $extensiones = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif'];
    private const MD5_HEX_LEN = 32;

    /** Columnas normalizadas usadas por rowToRecord y getImagen. */
    private const IMAGE_RECORD_COLUMNS = 'full_path, relative_path, folder_path, filename, content_md5';

    private function ws(): string
    {
        return \App\Services\AppConnection::currentSlug() ?? 'default';
    }

    public function __construct()
    {
        \App\Services\ConfigService::cargarYValidar();
        $this->pdo = \App\Services\AppConnection::get();
        \App\Services\AppSchema::ensure($this->pdo);
        $this->tImages = \App\Services\AppConnection::table('images');
        $this->tFolders = \App\Services\AppConnection::table('folders');
    }

    public static function resolverDirectorioBaseImagenes(): string
    {
        $ws = \App\Services\WorkspaceService::current();
        if ($ws === null) {
            // fallback legacy (no debería ocurrir por workspace gating)
            return __DIR__ . '/../../tmp/images';
        }
        \App\Services\WorkspaceService::ensureStructure($ws);
        return \App\Services\WorkspaceService::paths($ws)['imagesDir'];
    }

    private static function normalizarRelativa(string $ruta): string
    {
        $ruta = str_replace('\\', '/', $ruta);
        $ruta = preg_replace('#/+#', '/', $ruta);
        $ruta = ltrim($ruta, '/');
        return $ruta;
    }

    private static function normalizarCarpeta(string $rutaRelativa): string
    {
        $dir = dirname($rutaRelativa);
        return ($dir === '.' || $dir === DIRECTORY_SEPARATOR) ? '' : self::normalizarRelativa($dir);
    }

    private function rowToRecord(array $row): array
    {
        $relativePath = $row['relative_path'] ?? null;
        $folderPath = $row['folder_path'] ?? '';
        $filename = $row['filename'] ?? null;
        return [
            'full_path' => $row['full_path'] ?? null,
            'relative_path' => $relativePath,
            'folder_path' => $folderPath,
            'filename' => $filename,
            'content_md5' => $row['content_md5'] ?? null,
            'ruta_completa' => $row['full_path'] ?? null,
            'ruta_relativa' => $relativePath,
            'ruta_carpeta' => $folderPath,
            'archivo' => $filename
        ];
    }

    private function buildImagesUpsertStmt(): \PDOStatement
    {
        $driver = \App\Services\AppConnection::getCurrentDriver();
        if ($driver === 'mysql') {
            return $this->pdo->prepare("
                INSERT INTO " . $this->tImages . " (
                  workspace_slug, relative_path, full_path, folder_path, filename,
                  is_indexed, indexed_at, updated_at, file_mtime, file_size, scan_run, raw_md5
                ) VALUES (
                  :ws, :k, :full, :folder, :file,
                  1, :now, :now2, :mtime, :size, 1, :raw_md5
                )
                ON DUPLICATE KEY UPDATE
                  full_path = VALUES(full_path),
                  folder_path = VALUES(folder_path),
                  filename = VALUES(filename),
                  is_indexed = 1,
                  updated_at = VALUES(updated_at),
                  file_mtime = VALUES(file_mtime),
                  file_size = VALUES(file_size),
                  scan_run = 1,
                  raw_md5 = VALUES(raw_md5)
            ");
        }
        return $this->pdo->prepare("
            INSERT INTO " . $this->tImages . "(
              workspace_slug, relative_path, full_path, folder_path, filename,
              is_indexed, indexed_at, updated_at, file_mtime, file_size, scan_run, raw_md5
            ) VALUES(
              :ws, :k, :full, :folder, :file,
              1, :now, :now2, :mtime, :size, 1, :raw_md5
            )
            ON CONFLICT(workspace_slug, relative_path) DO UPDATE SET
              full_path=excluded.full_path,
              folder_path=excluded.folder_path,
              filename=excluded.filename,
              is_indexed=1,
              updated_at=excluded.updated_at,
              file_mtime=excluded.file_mtime,
              file_size=excluded.file_size,
              scan_run=1,
              raw_md5=excluded.raw_md5
        ");
    }

    private function buildFoldersUpsertStmt(): \PDOStatement
    {
        $driver = \App\Services\AppConnection::getCurrentDriver();
        if ($driver === 'mysql') {
            return $this->pdo->prepare("
                INSERT INTO " . $this->tFolders . "(workspace_slug, folder_path, name, search_key, image_count, updated_at)
                VALUES(:ws, :ruta, :nombre, :search_key, (SELECT COUNT(*) FROM " . $this->tImages . " WHERE workspace_slug = :ws2 AND folder_path = :ruta2), :now)
                ON DUPLICATE KEY UPDATE
                  name = VALUES(name),
                  search_key = VALUES(search_key),
                  image_count = (SELECT COUNT(*) FROM " . $this->tImages . " WHERE workspace_slug = :ws3 AND folder_path = VALUES(folder_path)),
                  updated_at = VALUES(updated_at)
            ");
        }
        return $this->pdo->prepare("
            INSERT INTO " . $this->tFolders . "(workspace_slug, folder_path, name, search_key, image_count, updated_at)
            VALUES(:ws, :ruta, :nombre, :search_key, (SELECT COUNT(*) FROM " . $this->tImages . " WHERE workspace_slug = :ws2 AND folder_path = :ruta2), :now)
            ON CONFLICT(workspace_slug, folder_path) DO UPDATE SET
              name = excluded.name,
              search_key = excluded.search_key,
              image_count = (SELECT COUNT(*) FROM " . $this->tImages . " WHERE workspace_slug = excluded.workspace_slug AND folder_path = excluded.folder_path),
              updated_at = excluded.updated_at
        ");
    }

    private static function md5FileSafe(string $path): ?string
    {
        if ($path === '' || !is_file($path)) return null;
        $md5 = @md5_file($path);
        if (!is_string($md5) || strlen($md5) !== self::MD5_HEX_LEN) return null;
        return strtolower($md5);
    }

    /**
     * Comprueba si ya existe otra fila en el índice con el mismo content_md5 o raw_md5 (dedupe: una sola fila por imagen única).
     */
    private function existeHashEnIndice(?string $contentMd5, ?string $rawMd5): bool
    {
        $contentMd5 = ($contentMd5 !== null && strlen($contentMd5) === self::MD5_HEX_LEN) ? strtolower($contentMd5) : null;
        $rawMd5 = ($rawMd5 !== null && strlen($rawMd5) === self::MD5_HEX_LEN) ? strtolower($rawMd5) : null;
        if ($contentMd5 === null && $rawMd5 === null) {
            return false;
        }
        $ws = $this->ws();
        $conditions = [];
        $params = [':ws' => $ws];
        if ($contentMd5 !== null) {
            $conditions[] = 'content_md5 = :cm';
            $params[':cm'] = $contentMd5;
        }
        if ($rawMd5 !== null) {
            $conditions[] = 'raw_md5 = :rm';
            $params[':rm'] = $rawMd5;
        }
        if (empty($conditions)) {
            return false;
        }
        $sql = 'SELECT 1 FROM ' . $this->tImages . ' WHERE workspace_slug = :ws AND (' . implode(' OR ', $conditions) . ') LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Precarga en memoria los content_md5 y raw_md5 existentes en el workspace (evita N+1 en sync/upsert).
     * @return array<string, true> mapa hash => true
     */
    private function loadExistingMd5Set(string $ws): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT content_md5, raw_md5 FROM " . $this->tImages . " WHERE workspace_slug = :ws AND (content_md5 IS NOT NULL OR raw_md5 IS NOT NULL)"
        );
        $stmt->execute([':ws' => $ws]);
        $set = [];
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $c = isset($row['content_md5']) && strlen((string)$row['content_md5']) === self::MD5_HEX_LEN ? strtolower((string)$row['content_md5']) : null;
            $r = isset($row['raw_md5']) && strlen((string)$row['raw_md5']) === self::MD5_HEX_LEN ? strtolower((string)$row['raw_md5']) : null;
            if ($c !== null) {
                $set[$c] = true;
            }
            if ($r !== null) {
                $set[$r] = true;
            }
        }
        return $set;
    }

    public function getImagenes(): array
    {
        $stmt = $this->pdo->prepare('SELECT relative_path, full_path, folder_path, filename, is_indexed FROM ' . $this->tImages . ' WHERE workspace_slug = :ws');
        $stmt->execute([':ws' => $this->ws()]);
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $key = (string)$r['relative_path'];
            $out[$key] = [
                'ruta_relativa' => $key,
                'ruta_completa' => $r['full_path'] ?? null,
                'ruta_carpeta' => $r['folder_path'] ?? '',
                'archivo' => $r['filename'] ?? null,
                'relative_path' => $key,
                'full_path' => $r['full_path'] ?? null,
                'folder_path' => $r['folder_path'] ?? '',
                'filename' => $r['filename'] ?? null,
                'indexada' => ((int)($r['is_indexed'] ?? 1)) !== 0
            ];
        }
        return $out;
    }

    public function getImagen(string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::IMAGE_RECORD_COLUMNS . ' FROM ' . $this->tImages . ' WHERE workspace_slug = :ws AND relative_path = :k');
        $stmt->execute([':ws' => $this->ws(), ':k' => $key]);
        $row = $stmt->fetch();
        return $row ? $this->rowToRecord($row) : null;
    }

    public function getStats(bool $recalcularSiInconsistente = true): array
    {
        $ws = $this->ws();
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) AS total FROM " . $this->tImages . " WHERE workspace_slug = :ws
        ");
        $stmt->execute([':ws' => $ws]);
        $total = (int)$stmt->fetchColumn();
        return [
            'total' => $total,
            'pendientes' => 0,
            'actualizada_en' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Sincroniza el índice con el filesystem (full sync).
     */
    public function sincronizarDesdeDirectorio(string $directorioBase): array
    {
        $ws = $this->ws();
        $directorioBaseReal = rtrim($directorioBase, "/\\");
        if (!is_dir($directorioBaseReal)) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM " . $this->tImages . " WHERE workspace_slug = :ws");
            $stmt->execute([':ws' => $ws]);
            $antes = (int)$stmt->fetchColumn();
            $del1 = $this->pdo->prepare("DELETE FROM " . $this->tImages . " WHERE workspace_slug = :ws");
            $del1->execute([':ws' => $ws]);
            $del2 = $this->pdo->prepare("DELETE FROM " . $this->tFolders . " WHERE workspace_slug = :ws");
            $del2->execute([':ws' => $ws]);
            return ['total_encontradas' => 0, 'nuevas' => 0, 'existentes' => 0, 'eliminadas' => $antes];
        }

        $upd = $this->pdo->prepare("UPDATE " . $this->tImages . " SET scan_run = 0 WHERE workspace_slug = :ws");
        $upd->execute([':ws' => $ws]);
        $ahora = date('Y-m-d H:i:s');
        $nuevas = 0;
        $existentes = 0;
        $omitidasDuplicado = 0;

        $stmtCheck = $this->pdo->prepare("SELECT 1 FROM " . $this->tImages . " WHERE workspace_slug = :ws AND relative_path = :k LIMIT 1");
        $stmtUpsert = $this->buildImagesUpsertStmt();
        $stmtSetMd5 = $this->pdo->prepare("UPDATE " . $this->tImages . " SET content_md5 = :m WHERE workspace_slug = :ws AND relative_path = :k");

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directorioBaseReal,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
            )
        );

        $baseNorm = str_replace('\\', '/', rtrim($directorioBaseReal, "/\\"));

        $existingMd5Set = $this->loadExistingMd5Set($ws);

        $this->pdo->beginTransaction();
        $i = 0;
        foreach ($iter as $file) {
            if (!$file->isFile()) continue;
            $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
            if (!in_array($ext, $this->extensiones, true)) continue;

            $rutaCompleta = $file->getPathname();
            if ($ext === 'heic' || $ext === 'heif') {
                $jpgPath = HeicConverter::convertFileToJpg($rutaCompleta);
                if ($jpgPath === null) continue;
                $rutaCompleta = $jpgPath;
                $ext = 'jpg';
            }
            $rutaNorm = str_replace('\\', '/', $rutaCompleta);
            $rutaRel = $rutaNorm;
            if (strpos($rutaNorm, $baseNorm . '/') === 0) {
                $rutaRel = substr($rutaNorm, strlen($baseNorm) + 1);
            }
            $rutaRel = self::normalizarRelativa($rutaRel);

            // Normalizar nombre de archivo/ruta (renombrar si tiene caracteres extraños)
            $rutaRelNormalized = StringNormalizer::normalizeRelativePath($rutaRel);
            if ($rutaRelNormalized !== '' && $rutaRelNormalized !== $rutaRel) {
                $targetFull = $baseNorm . '/' . $rutaRelNormalized;
                $currentReal = realpath($rutaCompleta);
                if ($currentReal === false) continue;
                if (!file_exists($targetFull) || realpath($targetFull) === $currentReal) {
                    $targetDir = dirname($targetFull);
                    if (!is_dir($targetDir)) {
                        @mkdir($targetDir, 0755, true);
                    }
                    if (@rename($rutaCompleta, $targetFull)) {
                        $rutaCompleta = $targetFull;
                        $rutaRel = $rutaRelNormalized;
                    }
                } else {
                    // Destino existe y es otro archivo: generar nombre único
                    $dirPart = dirname($rutaRelNormalized);
                    $basePart = pathinfo($rutaRelNormalized, PATHINFO_FILENAME);
                    $extPart = pathinfo($rutaRelNormalized, PATHINFO_EXTENSION);
                    if ($basePart === '') continue;
                    $suffix = 2;
                    while (true) {
                        $candidateRel = ($dirPart !== '.' && $dirPart !== '') ? $dirPart . '/' . $basePart . '_' . $suffix . '.' . $extPart : $basePart . '_' . $suffix . '.' . $extPart;
                        $candidateFull = $baseNorm . '/' . $candidateRel;
                        if (!file_exists($candidateFull)) {
                            $targetDir = dirname($candidateFull);
                            if (!is_dir($targetDir)) {
                                @mkdir($targetDir, 0755, true);
                            }
                            if (@rename($rutaCompleta, $candidateFull)) {
                                $rutaCompleta = $candidateFull;
                                $rutaRel = $candidateRel;
                            }
                            break;
                        }
                        $suffix++;
                        if ($suffix > 10000) break;
                    }
                }
            }

            // Comprimir imagen (jpg/png) para reducir peso; sobrescribir si el resultado es más pequeño
            if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                $content = @file_get_contents($rutaCompleta);
                if ($content !== false && $content !== '') {
                    $extForCompress = ($ext === 'jpeg') ? 'jpg' : $ext;
                    $compressed = ImageCompressor::compress($content, $extForCompress);
                    if ($compressed !== null && strlen($compressed) < strlen($content)) {
                        @file_put_contents($rutaCompleta, $compressed);
                    }
                }
            }

            $carpeta = self::normalizarCarpeta($rutaRel);
            $archivo = basename($rutaRel);

            $stmtCheck->execute([':ws' => $ws, ':k' => $rutaRel]);
            $exists = ($stmtCheck->fetchColumn() !== false);

            // Dedupe: si es ruta nueva y ya existe otra fila con el mismo content_md5, no insertar (imagen única)
            if (!$exists) {
                $md5Pre = self::md5FileSafe($rutaCompleta);
                if ($md5Pre !== null && isset($existingMd5Set[$md5Pre])) {
                    $omitidasDuplicado++;
                    continue;
                }
            }

            if ($exists) {
                $existentes++;
            } else {
                $nuevas++;
            }

            $stmtUpsert->execute([
                ':ws' => $ws,
                ':k' => $rutaRel,
                ':full' => $rutaCompleta,
                ':folder' => $carpeta,
                ':file' => $archivo,
                ':now' => $ahora,
                ':now2' => $ahora,
                ':mtime' => @filemtime($rutaCompleta) ?: null,
                ':size' => @filesize($rutaCompleta) ?: null,
                ':raw_md5' => null
            ]);

            // Guardar MD5 si es posible (si hay duplicado, el índice único puede rechazarlo)
            $md5 = self::md5FileSafe($rutaCompleta);
            if ($md5) {
                try {
                    $stmtSetMd5->execute([':m' => $md5, ':ws' => $ws, ':k' => $rutaRel]);
                    $existingMd5Set[$md5] = true;
                } catch (\Throwable $e) {
                    // Duplicado por md5 o fallo puntual: dejar content_md5 NULL
                }
            }

            $i++;
            if ($i % 2000 === 0) {
                $this->pdo->commit();
                $this->pdo->beginTransaction();
            }
        }
        $this->pdo->commit();

        $del = $this->pdo->prepare("DELETE FROM " . $this->tImages . " WHERE workspace_slug = :ws AND scan_run = 0");
        $del->execute([':ws' => $ws]);
        $eliminadas = $del->rowCount();

        (new CarpetasIndex())->regenerarDesdeImagenes($this);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM " . $this->tImages . " WHERE workspace_slug = :ws");
        $stmt->execute([':ws' => $ws]);
        $total = (int)$stmt->fetchColumn();

        return [
            'total_encontradas' => $total,
            'nuevas' => $nuevas,
            'existentes' => $existentes,
            'eliminadas' => $eliminadas,
            'omitidas_duplicado' => $omitidasDuplicado
        ];
    }

    /**
     * Upsert incremental desde rutas ya materializadas.
     * @param array $rawMd5PorRuta opcional: mapa ruta_completa => raw_md5 para persistir en el índice
     */
    public function upsertDesdeRutas(array $rutasCompletas, ?string $directorioBase = null, array $rawMd5PorRuta = []): array
    {
        $ws = $this->ws();
        $directorioBaseReal = rtrim(($directorioBase ?? self::resolverDirectorioBaseImagenes()), "/\\");
        $baseRealpath = realpath($directorioBaseReal) ?: $directorioBaseReal;
        $baseNorm = str_replace('\\', '/', rtrim($baseRealpath, "/\\"));
        $baseNormLower = strtolower($baseNorm);

        $ahora = date('Y-m-d H:i:s');
        $nuevas = 0;
        $existentes = 0;
        $ignoradas = 0;
        $omitidasDuplicado = 0;
        $foldersTouched = [];

        $stmtCheck = $this->pdo->prepare("SELECT 1 FROM " . $this->tImages . " WHERE workspace_slug = :ws AND relative_path = :k LIMIT 1");
        $stmtUpsert = $this->buildImagesUpsertStmt();
        $stmtSetMd5 = $this->pdo->prepare("UPDATE " . $this->tImages . " SET content_md5 = :m WHERE workspace_slug = :ws AND relative_path = :k");

        $existingMd5Set = $this->loadExistingMd5Set($ws);

        $this->pdo->beginTransaction();
        try {
            foreach ($rutasCompletas as $ruta) {
                if (!is_string($ruta)) { $ignoradas++; continue; }
                $ruta = trim($ruta);
                if ($ruta === '' || !file_exists($ruta) || !is_file($ruta)) { $ignoradas++; continue; }

                $ext = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));
                if (!in_array($ext, $this->extensiones, true)) { $ignoradas++; continue; }

                $rutaRealpath = realpath($ruta) ?: $ruta;
                if ($ext === 'heic' || $ext === 'heif') {
                    $jpgPath = HeicConverter::convertFileToJpg($rutaRealpath);
                    if ($jpgPath === null) { $ignoradas++; continue; }
                    $rutaRealpath = realpath($jpgPath) ?: $jpgPath;
                }
                $rutaNorm = str_replace('\\', '/', $rutaRealpath);
                $rutaNorm = rtrim($rutaNorm, '/');
                $rutaNormLower = strtolower($rutaNorm);
                if (strpos($rutaNormLower, $baseNormLower . '/') !== 0 && $rutaNormLower !== $baseNormLower) {
                    $ignoradas++;
                    continue;
                }

                $rutaRelativa = $rutaNorm;
                if (strpos($rutaNormLower, $baseNormLower . '/') === 0) {
                    $rutaRelativa = substr($rutaNorm, strlen($baseNorm) + 1);
                }
                $rutaRelativa = self::normalizarRelativa($rutaRelativa);
                $carpeta = self::normalizarCarpeta($rutaRelativa);

                $stmtCheck->execute([':ws' => $ws, ':k' => $rutaRelativa]);
                $exists = ($stmtCheck->fetchColumn() !== false);

                // Dedupe: si es ruta nueva y ya existe otra fila con el mismo content_md5 o raw_md5, no insertar (imagen única)
                if (!$exists) {
                    $contentMd5 = self::md5FileSafe($rutaRealpath);
                    $rawMd5Val = $rawMd5PorRuta[$ruta] ?? $rawMd5PorRuta[$rutaRealpath] ?? null;
                    $rawMd5Val = ($rawMd5Val !== null && strlen((string)$rawMd5Val) === 32) ? strtolower((string)$rawMd5Val) : null;
                    $dupe = ($contentMd5 !== null && isset($existingMd5Set[$contentMd5]))
                        || ($rawMd5Val !== null && isset($existingMd5Set[$rawMd5Val]));
                    if ($dupe) {
                        $omitidasDuplicado++;
                        continue;
                    }
                }

                if ($exists) {
                    $existentes++;
                } else {
                    $nuevas++;
                }

                $rawMd5 = $rawMd5PorRuta[$ruta] ?? $rawMd5PorRuta[$rutaRealpath] ?? null;
                $stmtUpsert->execute([
                    ':ws' => $ws,
                    ':k' => $rutaRelativa,
                    ':full' => $rutaRealpath,
                    ':folder' => $carpeta,
                    ':file' => basename($rutaRelativa),
                    ':now' => $ahora,
                    ':now2' => $ahora,
                    ':mtime' => @filemtime($rutaRealpath) ?: null,
                    ':size' => @filesize($rutaRealpath) ?: null,
                    ':raw_md5' => ($rawMd5 !== null && strlen((string)$rawMd5) === 32) ? $rawMd5 : null
                ]);

                // Guardar MD5 si es posible (si hay duplicado, el índice único puede rechazarlo)
                $md5 = self::md5FileSafe($rutaRealpath);
                $rawMd5ForSet = $rawMd5PorRuta[$ruta] ?? $rawMd5PorRuta[$rutaRealpath] ?? null;
                $rawMd5ForSet = ($rawMd5ForSet !== null && strlen((string)$rawMd5ForSet) === 32) ? strtolower((string)$rawMd5ForSet) : null;
                if ($md5) {
                    try {
                        $stmtSetMd5->execute([':m' => $md5, ':ws' => $ws, ':k' => $rutaRelativa]);
                        $existingMd5Set[$md5] = true;
                    } catch (\Throwable $e) {
                        // Duplicado por md5 o fallo puntual: dejar content_md5 NULL
                    }
                }
                if ($rawMd5ForSet !== null) {
                    $existingMd5Set[$rawMd5ForSet] = true;
                }

                $foldersTouched[$carpeta] = true;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $stmtFolder = $this->buildFoldersUpsertStmt();
        $driver = \App\Services\AppConnection::getCurrentDriver();
        foreach (array_keys($foldersTouched) as $rutaCarpeta) {
            $nombre = ($rutaCarpeta === '') ? '' : basename($rutaCarpeta);
            $searchKey = StringNormalizer::toSearchKey($rutaCarpeta . ' ' . $nombre);
            $params = [
                ':ws' => $ws,
                ':ruta' => $rutaCarpeta,
                ':ruta2' => $rutaCarpeta,
                ':nombre' => $nombre,
                ':search_key' => $searchKey,
                ':now' => $ahora
            ];
            if ($driver === 'mysql') {
                $params[':ws2'] = $ws;
                $params[':ws3'] = $ws;
            } else {
                $params[':ws2'] = $ws;
            }
            $stmtFolder->execute($params);
        }

        return ['nuevas' => $nuevas, 'existentes' => $existentes, 'ignoradas' => $ignoradas, 'omitidas_duplicado' => $omitidasDuplicado];
    }

}

