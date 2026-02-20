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
    private $tDetections;
    private $extensiones = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif'];
    private const MAX_RESULTADOS_BUSQUEDA_ETIQUETAS = 500;
    private const MD5_HEX_LEN = 32;

    /** Columnas usadas por rowToRecord(); evita SELECT * en getImagen y obtenerSiguientePendienteProcesar. */
    private const IMAGE_RECORD_COLUMNS = 'ruta_completa, ruta_relativa, ruta_carpeta, archivo, content_md5, safe, unsafe, resultado';

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
        $this->tDetections = \App\Services\AppConnection::table('detections');
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

    private static function normalizarLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '') return '';
        $label = strtoupper($label);
        $label = preg_replace('/\s+/', '_', $label);
        return $label;
    }

    private function obtenerUmbralClasificacion(): float
    {
        // Deprecado: antes se usaba un umbral global para safe/unsafe.
        // Nuevo criterio (detect-only): unsafe = cualquier detección no ignorada.
        return 0.0;
    }

    private function calcularResultado(?float $safe, ?float $unsafe): ?string
    {
        if ($safe === null || $unsafe === null) return null;
        $umbral = $this->obtenerUmbralClasificacion();
        if ($unsafe > $safe && $unsafe >= $umbral) return 'unsafe';
        if ($safe > $unsafe && $safe >= $umbral) return 'safe';
        return null;
    }

    private function rowToRecord(array $row): array
    {
        return [
            'ruta_completa' => $row['ruta_completa'] ?? null,
            'ruta_relativa' => $row['ruta_relativa'] ?? null,
            'ruta_carpeta' => $row['ruta_carpeta'] ?? '',
            'archivo' => $row['archivo'] ?? null,
            'content_md5' => $row['content_md5'] ?? null,
            'safe' => isset($row['safe']) ? (float)$row['safe'] : null,
            'unsafe' => isset($row['unsafe']) ? (float)$row['unsafe'] : null,
            'resultado' => $row['resultado'] ?? null
        ];
    }

    private function buildImagesUpsertStmt(): \PDOStatement
    {
        $driver = \App\Services\AppConnection::getCurrentDriver();
        if ($driver === 'mysql') {
            return $this->pdo->prepare("
                INSERT INTO " . $this->tImages . " (
                  workspace_slug, ruta_relativa, ruta_completa, ruta_carpeta, archivo,
                  indexada, indexada_en, actualizada_en, mtime, tamano, seen_run,
                  clasif_estado, detect_requerida, detect_estado, raw_md5
                ) VALUES (
                  :ws, :k, :full, :folder, :file,
                  1, :now, :now2, :mtime, :size, 1,
                  'pending', 1, 'pending', :raw_md5
                )
                ON DUPLICATE KEY UPDATE
                  ruta_completa = VALUES(ruta_completa),
                  ruta_carpeta = VALUES(ruta_carpeta),
                  archivo = VALUES(archivo),
                  indexada = 1,
                  actualizada_en = VALUES(actualizada_en),
                  mtime = VALUES(mtime),
                  tamano = VALUES(tamano),
                  seen_run = 1,
                  raw_md5 = VALUES(raw_md5)
            ");
        }
        return $this->pdo->prepare("
            INSERT INTO " . $this->tImages . "(
              workspace_slug, ruta_relativa, ruta_completa, ruta_carpeta, archivo,
              indexada, indexada_en, actualizada_en, mtime, tamano, seen_run,
              clasif_estado, detect_requerida, detect_estado, raw_md5
            ) VALUES(
              :ws, :k, :full, :folder, :file,
              1, :now, :now2, :mtime, :size, 1,
              'pending', 1, 'pending', :raw_md5
            )
            ON CONFLICT(workspace_slug, ruta_relativa) DO UPDATE SET
              ruta_completa=excluded.ruta_completa,
              ruta_carpeta=excluded.ruta_carpeta,
              archivo=excluded.archivo,
              indexada=1,
              actualizada_en=excluded.actualizada_en,
              mtime=excluded.mtime,
              tamano=excluded.tamano,
              seen_run=1,
              raw_md5=excluded.raw_md5
        ");
    }

    private function buildFoldersUpsertStmt(): \PDOStatement
    {
        $driver = \App\Services\AppConnection::getCurrentDriver();
        if ($driver === 'mysql') {
            return $this->pdo->prepare("
                INSERT INTO " . $this->tFolders . "(workspace_slug, ruta_carpeta, nombre, search_key, total_imagenes, actualizada_en)
                VALUES(:ws, :ruta, :nombre, :search_key, (SELECT COUNT(*) FROM " . $this->tImages . " WHERE workspace_slug = :ws2 AND ruta_carpeta = :ruta2), :now)
                ON DUPLICATE KEY UPDATE
                  nombre = VALUES(nombre),
                  search_key = VALUES(search_key),
                  total_imagenes = (SELECT COUNT(*) FROM " . $this->tImages . " WHERE workspace_slug = :ws3 AND ruta_carpeta = VALUES(ruta_carpeta)),
                  actualizada_en = VALUES(actualizada_en)
            ");
        }
        return $this->pdo->prepare("
            INSERT INTO " . $this->tFolders . "(workspace_slug, ruta_carpeta, nombre, search_key, total_imagenes, actualizada_en)
            VALUES(:ws, :ruta, :nombre, :search_key, (SELECT COUNT(*) FROM " . $this->tImages . " WHERE workspace_slug = :ws2 AND ruta_carpeta = :ruta2), :now)
            ON CONFLICT(workspace_slug, ruta_carpeta) DO UPDATE SET
              nombre = excluded.nombre,
              search_key = excluded.search_key,
              total_imagenes = (SELECT COUNT(*) FROM " . $this->tImages . " WHERE workspace_slug = excluded.workspace_slug AND ruta_carpeta = excluded.ruta_carpeta),
              actualizada_en = excluded.actualizada_en
        ");
    }

    private static function md5FileSafe(string $path): ?string
    {
        if ($path === '' || !is_file($path)) return null;
        $md5 = @md5_file($path);
        if (!is_string($md5) || strlen($md5) !== self::MD5_HEX_LEN) return null;
        return strtolower($md5);
    }

    public function getImagenes(): array
    {
        $stmt = $this->pdo->prepare('SELECT ruta_relativa, ruta_completa, ruta_carpeta, archivo, indexada, clasif_estado, safe, unsafe, resultado FROM ' . $this->tImages . ' WHERE workspace_slug = :ws');
        $stmt->execute([':ws' => $this->ws()]);
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $key = (string)$r['ruta_relativa'];
            $out[$key] = [
                'ruta_relativa' => $key,
                'ruta_completa' => $r['ruta_completa'] ?? null,
                'ruta_carpeta' => $r['ruta_carpeta'] ?? '',
                'archivo' => $r['archivo'] ?? null,
                'indexada' => ((int)($r['indexada'] ?? 1)) !== 0,
                'procesada' => ((string)($r['clasif_estado'] ?? 'pending')) !== 'pending',
                'safe' => $r['safe'],
                'unsafe' => $r['unsafe'],
                'resultado' => $r['resultado'] ?? null
            ];
        }
        return $out;
    }

    public function getImagen(string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::IMAGE_RECORD_COLUMNS . ' FROM ' . $this->tImages . ' WHERE workspace_slug = :ws AND ruta_relativa = :k');
        $stmt->execute([':ws' => $this->ws(), ':k' => $key]);
        $row = $stmt->fetch();
        return $row ? $this->rowToRecord($row) : null;
    }

    public function getStats(bool $recalcularSiInconsistente = true): array
    {
        $ws = $this->ws();
        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN clasif_estado IS NOT NULL AND clasif_estado <> 'pending' THEN 1 ELSE 0 END) AS procesadas,
                SUM(CASE WHEN resultado = 'safe' THEN 1 ELSE 0 END) AS safe,
                SUM(CASE WHEN resultado = 'unsafe' THEN 1 ELSE 0 END) AS unsafe
            FROM " . $this->tImages . "
            WHERE workspace_slug = :ws
        ");
        $stmt->execute([':ws' => $ws]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $total = (int)($row['total'] ?? 0);
        $procesadas = (int)($row['procesadas'] ?? 0);
        $safe = (int)($row['safe'] ?? 0);
        $unsafe = (int)($row['unsafe'] ?? 0);
        return [
            'total' => $total,
            'procesadas' => $procesadas,
            'pendientes' => max(0, $total - $procesadas),
            'safe' => $safe,
            'unsafe' => $unsafe,
            'actualizada_en' => date('Y-m-d H:i:s')
        ];
    }

    public function getStatsDeteccion(bool $recalcularSiInconsistente = true): array
    {
        $ws = $this->ws();
        $stmt = $this->pdo->prepare("
            SELECT
                SUM(CASE WHEN detect_requerida = 1 THEN 1 ELSE 0 END) AS requeridas,
                SUM(CASE WHEN detect_requerida = 1 AND detect_estado = 'ok' THEN 1 ELSE 0 END) AS procesadas,
                SUM(CASE WHEN detect_requerida = 1 AND (detect_estado = 'pending' OR detect_estado = 'na' OR detect_estado IS NULL OR detect_estado = 'error') THEN 1 ELSE 0 END) AS pendientes
            FROM " . $this->tImages . "
            WHERE workspace_slug = :ws
        ");
        $stmt->execute([':ws' => $ws]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $requeridas = (int)($row['requeridas'] ?? 0);
        $procesadas = (int)($row['procesadas'] ?? 0);
        $pendientes = (int)($row['pendientes'] ?? 0);

        $stmt = $this->pdo->prepare("
            SELECT label, COUNT(DISTINCT image_ruta_relativa) as c
            FROM " . $this->tDetections . "
            WHERE workspace_slug = :ws
            GROUP BY label
        ");
        $stmt->execute([':ws' => $ws]);
        $rows = $stmt->fetchAll();
        $map = [];
        foreach ($rows as $r) {
            $map[(string)$r['label']] = (int)$r['c'];
        }

        return [
            'requeridas' => $requeridas,
            'procesadas' => $procesadas,
            'pendientes' => $pendientes,
            'etiquetas' => $map,
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

        $upd = $this->pdo->prepare("UPDATE " . $this->tImages . " SET seen_run = 0 WHERE workspace_slug = :ws");
        $upd->execute([':ws' => $ws]);
        $ahora = date('Y-m-d H:i:s');
        $nuevas = 0;
        $existentes = 0;

        $stmtCheck = $this->pdo->prepare("SELECT 1 FROM " . $this->tImages . " WHERE workspace_slug = :ws AND ruta_relativa = :k LIMIT 1");
        $stmtUpsert = $this->buildImagesUpsertStmt();
        $stmtSetMd5 = $this->pdo->prepare("UPDATE " . $this->tImages . " SET content_md5 = :m WHERE workspace_slug = :ws AND ruta_relativa = :k");

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directorioBaseReal,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
            )
        );

        $baseNorm = str_replace('\\', '/', rtrim($directorioBaseReal, "/\\"));

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
            if ($exists) $existentes++; else $nuevas++;

            $stmtUpsert->execute([
                ':ws' => $ws,
                ':k' => $rutaRel,
                ':full' => $rutaCompleta,
                ':folder' => $carpeta,
                ':file' => $archivo,
                ':now' => $ahora,
                ':now2' => $ahora,
                ':mtime' => @filemtime($rutaCompleta) ?: null,
                ':size' => @filesize($rutaCompleta) ?: null
            ]);

            // Guardar MD5 si es posible (si hay duplicado, el índice único puede rechazarlo)
            $md5 = self::md5FileSafe($rutaCompleta);
            if ($md5) {
                try {
                    $stmtSetMd5->execute([':m' => $md5, ':ws' => $ws, ':k' => $rutaRel]);
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

        $del = $this->pdo->prepare("DELETE FROM " . $this->tImages . " WHERE workspace_slug = :ws AND seen_run = 0");
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
            'eliminadas' => $eliminadas
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
        $foldersTouched = [];

        $stmtCheck = $this->pdo->prepare("SELECT 1 FROM " . $this->tImages . " WHERE workspace_slug = :ws AND ruta_relativa = :k LIMIT 1");
        $stmtUpsert = $this->buildImagesUpsertStmt();
        $stmtSetMd5 = $this->pdo->prepare("UPDATE " . $this->tImages . " SET content_md5 = :m WHERE workspace_slug = :ws AND ruta_relativa = :k");

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
                if ($exists) $existentes++; else $nuevas++;

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
                if ($md5) {
                    try {
                        $stmtSetMd5->execute([':m' => $md5, ':ws' => $ws, ':k' => $rutaRelativa]);
                    } catch (\Throwable $e) {
                        // Duplicado por md5 o fallo puntual: dejar content_md5 NULL
                    }
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

        return ['nuevas' => $nuevas, 'existentes' => $existentes, 'ignoradas' => $ignoradas];
    }

    /**
     * Devuelve [key, record, fase] donde fase ∈ {'clasificacion','deteccion'}
     */
    public function obtenerSiguientePendienteProcesar(): ?array
    {
        $ws = $this->ws();
        $stmt = $this->pdo->prepare("
            SELECT " . self::IMAGE_RECORD_COLUMNS . "
            FROM " . $this->tImages . "
            WHERE workspace_slug = :ws
              AND (detect_estado = 'pending' OR detect_estado = 'na' OR detect_estado IS NULL OR detect_estado = 'error')
            ORDER BY CASE WHEN detect_estado = 'error' THEN 0 ELSE 1 END, ruta_relativa ASC
            LIMIT 1
        ");
        $stmt->execute([':ws' => $ws]);
        $row = $stmt->fetch();
        if ($row) {
            $k = (string)$row['ruta_relativa'];
            return [$k, $this->rowToRecord($row), 'deteccion'];
        }

        $stmt = $this->pdo->prepare("SELECT " . self::IMAGE_RECORD_COLUMNS . " FROM " . $this->tImages . " WHERE workspace_slug = :ws AND clasif_estado = 'pending' ORDER BY ruta_relativa ASC LIMIT 1");
        $stmt->execute([':ws' => $ws]);
        $row = $stmt->fetch();
        if ($row) {
            $k = (string)$row['ruta_relativa'];
            return [$k, $this->rowToRecord($row), 'deteccion'];
        }

        return null;
    }

    public function marcarError(string $key, string $mensaje): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("
            UPDATE " . $this->tImages . "
            SET clasif_estado='error', clasif_error=:e, clasif_en=:t, actualizada_en=:t2, resultado=NULL
            WHERE workspace_slug = :ws AND ruta_relativa = :k
        ");
        $stmt->execute([':e' => $mensaje, ':t' => $now, ':t2' => $now, ':ws' => $this->ws(), ':k' => $key]);
    }

    public function marcarEmpty(string $key, string $mensaje = 'empty'): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("
            UPDATE " . $this->tImages . "
            SET clasif_estado='empty',
                clasif_error=:e,
                clasif_en=:t,
                actualizada_en=:t2,
                safe=NULL,
                unsafe=NULL,
                resultado=NULL,
                detect_requerida=0,
                detect_estado='na',
                detect_error=NULL,
                detect_en=NULL
            WHERE workspace_slug = :ws AND ruta_relativa = :k
        ");
        $stmt->execute([':e' => $mensaje, ':t' => $now, ':t2' => $now, ':ws' => $this->ws(), ':k' => $key]);
    }

    public function marcarProcesada(string $key, float $safe, float $unsafe): void
    {
        $now = date('Y-m-d H:i:s');
        $resultado = $this->calcularResultado($safe, $unsafe);
        $detectRequerida = ($resultado === 'unsafe') ? 1 : 0;

        $stmt = $this->pdo->prepare("
            UPDATE " . $this->tImages . "
            SET clasif_estado='ok',
                safe=:s,
                unsafe=:u,
                resultado=:r,
                clasif_error=NULL,
                clasif_en=:t,
                actualizada_en=:t2,
                detect_requerida=:dr,
                detect_estado=CASE
                  WHEN :dr = 1 AND (detect_estado='na' OR detect_estado IS NULL) THEN 'pending'
                  WHEN :dr = 0 THEN 'na'
                  ELSE detect_estado
                END,
                detect_error=CASE WHEN :dr = 0 THEN NULL ELSE detect_error END,
                detect_en=CASE WHEN :dr = 0 THEN NULL ELSE detect_en END
            WHERE workspace_slug=:ws AND ruta_relativa=:k
        ");
        $stmt->execute([
            ':s' => $safe,
            ':u' => $unsafe,
            ':r' => $resultado,
            ':t' => $now,
            ':t2' => $now,
            ':dr' => $detectRequerida,
            ':ws' => $this->ws(),
            ':k' => $key
        ]);
    }

    public function marcarDeteccion(string $key, array $detecciones, ?string $resultado = null, ?float $unsafeScore = null): void
    {
        $now = date('Y-m-d H:i:s');
        $hasClasif = (is_string($resultado) && ($resultado === 'safe' || $resultado === 'unsafe')) ? 1 : 0;
        $unsafeVal = is_numeric($unsafeScore) ? (float)$unsafeScore : 0.0;
        $safeVal = ($resultado === 'unsafe') ? 0.0 : 1.0;
        $this->pdo->beginTransaction();
        try {
            // Nota: en algunos entornos SQLite/PDO la comparación de parámetros en CASE puede comportarse raro.
            // Para asegurar consistencia, armamos el UPDATE en PHP y solo seteamos clasif_* si tenemos resultado.
            $sql = "
                UPDATE " . $this->tImages . "
                SET
                  detect_requerida=1,
                  detect_estado='ok',
                  detect_error=NULL,
                  detect_en=:t,
                  actualizada_en=:t2
            ";
            $params = [
                ':t' => $now,
                ':t2' => $now,
                ':k' => $key
            ];

            if ($hasClasif === 1) {
                $sql .= ",
                  clasif_estado='ok',
                  resultado=:r,
                  safe=:safe,
                  unsafe=:unsafe,
                  clasif_error=NULL,
                  clasif_en=:t3
                ";
                $params[':r'] = $resultado;
                $params[':safe'] = $safeVal;
                $params[':unsafe'] = $unsafeVal;
                $params[':t3'] = $now;
            }

            $sql .= " WHERE workspace_slug=:ws AND ruta_relativa=:k";

            $params[':ws'] = $this->ws();
            $upd = $this->pdo->prepare($sql);
            $upd->execute($params);

            $ws = $this->ws();
            $del = $this->pdo->prepare("DELETE FROM " . $this->tDetections . " WHERE workspace_slug = :ws AND image_ruta_relativa = :k");
            $del->execute([':ws' => $ws, ':k' => $key]);

            $ins = $this->pdo->prepare("
                INSERT INTO " . $this->tDetections . "(workspace_slug, image_ruta_relativa, label, score, x1, y1, x2, y2)
                VALUES(:ws, :img, :label, :score, :x1, :y1, :x2, :y2)
            ");

            foreach ($detecciones as $d) {
                if (!is_array($d)) continue;
                $label = isset($d['label']) ? self::normalizarLabel((string)$d['label']) : '';
                if ($label === '') continue;
                $score = isset($d['score']) && is_numeric($d['score']) ? (float)$d['score'] : null;
                if ($score === null) continue;

                $x1 = $y1 = $x2 = $y2 = null;
                $box = $d['box'] ?? null;
                if (is_array($box) && count($box) === 4) {
                    $x1 = (int)$box[0];
                    $y1 = (int)$box[1];
                    $x2 = (int)$box[2];
                    $y2 = (int)$box[3];
                }

                $ins->execute([
                    ':ws' => $ws,
                    ':img' => $key,
                    ':label' => $label,
                    ':score' => $score,
                    ':x1' => $x1,
                    ':y1' => $y1,
                    ':x2' => $x2,
                    ':y2' => $y2
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function marcarDeteccionError(string $key, string $mensaje): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("
            UPDATE " . $this->tImages . "
            SET
              detect_requerida=1,
              detect_estado='error',
              detect_error=:e,
              detect_en=:t,
              actualizada_en=:t2,
              clasif_estado='error',
              clasif_error=:e2,
              clasif_en=:t3,
              resultado=NULL
            WHERE workspace_slug=:ws AND ruta_relativa=:k
        ");
        $stmt->execute([':e' => $mensaje, ':e2' => $mensaje, ':t' => $now, ':t2' => $now, ':t3' => $now, ':ws' => $this->ws(), ':k' => $key]);
    }

    public function getEtiquetasDetectadas(bool $recalcularSiInconsistente = true): array
    {
        $stmt = $this->pdo->prepare("
            SELECT label,
                   COUNT(DISTINCT image_ruta_relativa) as c,
                   MIN(score) as min_score,
                   MAX(score) as max_score
            FROM " . $this->tDetections . "
            WHERE workspace_slug = :ws
            GROUP BY label
            ORDER BY c DESC, label ASC
        ");
        $stmt->execute([':ws' => $this->ws()]);
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $minScore = isset($r['min_score']) ? (float)$r['min_score'] : 0;
            $maxScore = isset($r['max_score']) ? (float)$r['max_score'] : 0;
            $out[] = [
                'label' => (string)$r['label'],
                'count' => (int)$r['c'],
                'min' => (int)round($minScore * 100),
                'max' => (int)round($maxScore * 100),
            ];
        }
        usort($out, fn($a, $b) => ($b['max'] <=> $a['max']) ?: ($b['count'] <=> $a['count']) ?: strcmp($a['label'], $b['label']));
        return $out;
    }

    public function buscarPorEtiquetas(array $labels, int $umbralPercent): array
    {
        $umbralPercent = max(0, min(100, (int)$umbralPercent));
        $umbral = $umbralPercent / 100.0;

        $labelsNorm = [];
        foreach ($labels as $lab) {
            $lab = self::normalizarLabel((string)$lab);
            if ($lab !== '') $labelsNorm[] = $lab;
        }
        $labelsNorm = array_values(array_unique($labelsNorm));
        if (empty($labelsNorm)) return [];

        $ws = $this->ws();

        $labelsToUse = [];
        try {
            $stmtL = $this->pdo->prepare("SELECT DISTINCT label FROM " . $this->tDetections . " WHERE workspace_slug = :ws");
            $stmtL->execute([':ws' => $ws]);
            while ($stmtL && ($row = $stmtL->fetch(\PDO::FETCH_NUM))) {
                $raw = (string)($row[0] ?? '');
                if ($raw !== '' && in_array(self::normalizarLabel($raw), $labelsNorm, true)) {
                    $labelsToUse[$raw] = true;
                }
            }
            $labelsToUse = array_keys($labelsToUse);
        } catch (\Throwable $e) {
            $labelsToUse = [];
        }
        if (empty($labelsToUse)) return [];

        $ph = implode(',', array_fill(0, count($labelsToUse), '?'));
        $stmtTop = $this->pdo->prepare("
            SELECT image_ruta_relativa, MAX(score) AS best_score
            FROM " . $this->tDetections . "
            WHERE workspace_slug = ? AND label IN ($ph) AND score >= ?
            GROUP BY image_ruta_relativa
            ORDER BY best_score DESC
            LIMIT " . self::MAX_RESULTADOS_BUSQUEDA_ETIQUETAS . "
        ");
        $stmtTop->execute(array_merge([$ws], $labelsToUse, [$umbral]));
        $top = $stmtTop->fetchAll();
        if (empty($top)) return [];

        $imageKeys = array_map(fn($r) => (string)$r['image_ruta_relativa'], $top);
        $bestByKey = [];
        foreach ($top as $r) $bestByKey[(string)$r['image_ruta_relativa']] = (float)$r['best_score'];

        $ph2 = implode(',', array_fill(0, count($imageKeys), '?'));
        $stmtInfo = $this->pdo->prepare("SELECT ruta_relativa, ruta_carpeta, archivo FROM " . $this->tImages . " WHERE workspace_slug = ? AND ruta_relativa IN ($ph2)");
        $stmtInfo->execute(array_merge([$ws], $imageKeys));
        $infoRows = $stmtInfo->fetchAll();
        $infoByKey = [];
        foreach ($infoRows as $r) {
            $infoByKey[(string)$r['ruta_relativa']] = $r;
        }
        $imageKeys = array_values(array_filter($imageKeys, fn($k) => isset($infoByKey[$k])));
        if (empty($imageKeys)) return [];

        $stmtMatches = $this->pdo->prepare("
            SELECT image_ruta_relativa, label, MAX(score) AS score
            FROM " . $this->tDetections . "
            WHERE workspace_slug = ? AND image_ruta_relativa IN ($ph2) AND label IN ($ph) AND score >= ?
            GROUP BY image_ruta_relativa, label
        ");
        $stmtMatches->execute(array_merge([$ws], $imageKeys, $labelsToUse, [$umbral]));
        $matchRows = $stmtMatches->fetchAll();
        $matchesByKey = [];
        foreach ($matchRows as $r) {
            $k = (string)$r['image_ruta_relativa'];
            $matchesByKey[$k][] = ['label' => (string)$r['label'], 'score' => (float)$r['score']];
        }

        $resultados = [];
        foreach ($imageKeys as $k) {
            $info = $infoByKey[$k] ?? null;
            if (!$info) continue;
            $resultados[] = [
                'ruta_relativa' => $k,
                'ruta_carpeta' => (string)($info['ruta_carpeta'] ?? ''),
                'archivo' => (string)($info['archivo'] ?? ''),
                'best_score' => (float)($bestByKey[$k] ?? 0),
                'matches' => $matchesByKey[$k] ?? []
            ];
        }

        usort($resultados, fn($a, $b) => ($b['best_score'] <=> $a['best_score']));
        return $resultados;
    }

    public function buscarPorUmbral(string $tipo, int $umbralPercent): array
    {
        $tipo = strtolower(trim($tipo));
        if (!in_array($tipo, ['safe', 'unsafe', 'error', 'empty'], true)) {
            return [];
        }

        $ws = $this->ws();
        if ($tipo === 'error' || $tipo === 'empty') {
            $stmt = $this->pdo->prepare("
                SELECT ruta_relativa, ruta_carpeta, archivo, clasif_estado, clasif_error, clasif_en
                FROM " . $this->tImages . "
                WHERE workspace_slug = :ws AND clasif_estado = :st
                ORDER BY COALESCE(clasif_en, actualizada_en) DESC
                LIMIT 2000
            ");
            $stmt->execute([':ws' => $ws, ':st' => $tipo]);
            $rows = $stmt->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'ruta_relativa' => (string)$r['ruta_relativa'],
                    'ruta_carpeta' => (string)($r['ruta_carpeta'] ?? ''),
                    'archivo' => (string)($r['archivo'] ?? ''),
                    'estado' => (string)($r['clasif_estado'] ?? $tipo),
                    'error' => $r['clasif_error'] ?? null,
                    'score' => null
                ];
            }
            return $out;
        }

        $umbralPercent = max(0, min(100, $umbralPercent));
        $umbral = $umbralPercent / 100.0;

        $field = ($tipo === 'unsafe') ? 'unsafe' : 'safe';
        $fieldOther = ($tipo === 'unsafe') ? 'safe' : 'unsafe';
        $stmt = $this->pdo->prepare("
            SELECT ruta_relativa, ruta_carpeta, archivo, safe, unsafe, {$field} as score
            FROM " . $this->tImages . "
            WHERE workspace_slug = :ws
              AND clasif_estado <> 'pending'
              AND {$field} > {$fieldOther}
              AND {$field} >= :u
            ORDER BY score DESC
            LIMIT 2000
        ");
        $stmt->execute([':ws' => $ws, ':u' => $umbral]);
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'ruta_relativa' => (string)$r['ruta_relativa'],
                'ruta_carpeta' => (string)($r['ruta_carpeta'] ?? ''),
                'archivo' => (string)($r['archivo'] ?? ''),
                'safe' => $r['safe'],
                'unsafe' => $r['unsafe'],
                'score' => (float)$r['score']
            ];
        }
        return $out;
    }
}

