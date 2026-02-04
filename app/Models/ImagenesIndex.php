<?php

namespace App\Models;

use App\Services\HeicConverter;
use App\Services\StringNormalizer;

/**
 * Índice persistente de imágenes en SQLite (tmp/db/clasificador.sqlite)
 * - Sincroniza con el filesystem sin perder resultados ya procesados.
 * - Permite seleccionar pendientes y buscar usando solo el índice.
 */
class ImagenesIndex
{
    private $pdo;
    private $extensiones = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif'];
    private const MAX_RESULTADOS_BUSQUEDA_ETIQUETAS = 500;
    private const MD5_HEX_LEN = 32;

    public function __construct()
    {
        \App\Services\ConfigService::cargarYValidar();
        $this->pdo = \App\Services\SqliteConnection::get();
        \App\Services\SqliteSchema::ensure($this->pdo);
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

    private static function md5FileSafe(string $path): ?string
    {
        if ($path === '' || !is_file($path)) return null;
        $md5 = @md5_file($path);
        if (!is_string($md5) || strlen($md5) !== self::MD5_HEX_LEN) return null;
        return strtolower($md5);
    }

    public function getImagenes(): array
    {
        $rows = $this->pdo->query('SELECT ruta_relativa, ruta_completa, ruta_carpeta, archivo, indexada, clasif_estado, safe, unsafe, resultado FROM images')->fetchAll();
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
        $stmt = $this->pdo->prepare('SELECT * FROM images WHERE ruta_relativa = :k');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch();
        return $row ? $this->rowToRecord($row) : null;
    }

    public function getStats(bool $recalcularSiInconsistente = true): array
    {
        $total = (int)$this->pdo->query("SELECT COUNT(*) FROM images")->fetchColumn();
        $procesadas = (int)$this->pdo->query("SELECT COUNT(*) FROM images WHERE clasif_estado <> 'pending'")->fetchColumn();
        $safe = (int)$this->pdo->query("SELECT COUNT(*) FROM images WHERE resultado = 'safe'")->fetchColumn();
        $unsafe = (int)$this->pdo->query("SELECT COUNT(*) FROM images WHERE resultado = 'unsafe'")->fetchColumn();
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
        $requeridas = (int)$this->pdo->query("SELECT COUNT(*) FROM images WHERE detect_requerida = 1")->fetchColumn();
        $procesadas = (int)$this->pdo->query("SELECT COUNT(*) FROM images WHERE detect_requerida = 1 AND detect_estado = 'ok'")->fetchColumn();
        $pendientes = (int)$this->pdo->query("
            SELECT COUNT(*)
            FROM images
            WHERE detect_requerida = 1
              AND (detect_estado = 'pending' OR detect_estado = 'na' OR detect_estado IS NULL)
        ")->fetchColumn();

        $rows = $this->pdo->query("
            SELECT label, COUNT(DISTINCT image_ruta_relativa) as c
            FROM detections
            GROUP BY label
        ")->fetchAll();
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
        $directorioBaseReal = rtrim($directorioBase, "/\\");
        if (!is_dir($directorioBaseReal)) {
            $antes = (int)$this->pdo->query("SELECT COUNT(*) FROM images")->fetchColumn();
            $this->pdo->exec("DELETE FROM images");
            $this->pdo->exec("DELETE FROM folders");
            return ['total_encontradas' => 0, 'nuevas' => 0, 'existentes' => 0, 'eliminadas' => $antes];
        }

        $this->pdo->exec("UPDATE images SET seen_run = 0");
        $ahora = date('Y-m-d H:i:s');
        $nuevas = 0;
        $existentes = 0;

        $stmtCheck = $this->pdo->prepare("SELECT 1 FROM images WHERE ruta_relativa = :k LIMIT 1");
        $stmtUpsert = $this->pdo->prepare("
            INSERT INTO images(
              ruta_relativa, ruta_completa, ruta_carpeta, archivo,
              indexada, indexada_en, actualizada_en, mtime, tamano, seen_run,
              clasif_estado, detect_requerida, detect_estado
            ) VALUES(
              :k, :full, :folder, :file,
              1, :now, :now2, :mtime, :size, 1,
              'pending', 1, 'pending'
            )
            ON CONFLICT(ruta_relativa) DO UPDATE SET
              ruta_completa=excluded.ruta_completa,
              ruta_carpeta=excluded.ruta_carpeta,
              archivo=excluded.archivo,
              indexada=1,
              actualizada_en=excluded.actualizada_en,
              mtime=excluded.mtime,
              tamano=excluded.tamano,
              seen_run=1
        ");
        $stmtSetMd5 = $this->pdo->prepare("UPDATE images SET content_md5 = :m WHERE ruta_relativa = :k");

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
            }
            $rutaNorm = str_replace('\\', '/', $rutaCompleta);
            $rutaRel = $rutaNorm;
            if (strpos($rutaNorm, $baseNorm . '/') === 0) {
                $rutaRel = substr($rutaNorm, strlen($baseNorm) + 1);
            }
            $rutaRel = self::normalizarRelativa($rutaRel);
            $carpeta = self::normalizarCarpeta($rutaRel);
            $archivo = basename($rutaRel);

            $stmtCheck->execute([':k' => $rutaRel]);
            $exists = ($stmtCheck->fetchColumn() !== false);
            if ($exists) $existentes++; else $nuevas++;

            $stmtUpsert->execute([
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
                    $stmtSetMd5->execute([':m' => $md5, ':k' => $rutaRel]);
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

        $del = $this->pdo->prepare("DELETE FROM images WHERE seen_run = 0");
        $del->execute();
        $eliminadas = $del->rowCount();

        (new CarpetasIndex())->regenerarDesdeImagenes($this);
        $total = (int)$this->pdo->query("SELECT COUNT(*) FROM images")->fetchColumn();

        return [
            'total_encontradas' => $total,
            'nuevas' => $nuevas,
            'existentes' => $existentes,
            'eliminadas' => $eliminadas
        ];
    }

    /**
     * Upsert incremental desde rutas ya materializadas.
     */
    public function upsertDesdeRutas(array $rutasCompletas, ?string $directorioBase = null): array
    {
        $directorioBaseReal = rtrim(($directorioBase ?? self::resolverDirectorioBaseImagenes()), "/\\");
        $baseRealpath = realpath($directorioBaseReal) ?: $directorioBaseReal;
        $baseNorm = str_replace('\\', '/', rtrim($baseRealpath, "/\\"));
        $baseNormLower = strtolower($baseNorm);

        $ahora = date('Y-m-d H:i:s');
        $nuevas = 0;
        $existentes = 0;
        $ignoradas = 0;
        $foldersTouched = [];

        $stmtCheck = $this->pdo->prepare("SELECT 1 FROM images WHERE ruta_relativa = :k LIMIT 1");
        $stmtUpsert = $this->pdo->prepare("
            INSERT INTO images(ruta_relativa, ruta_completa, ruta_carpeta, archivo, indexada, indexada_en, actualizada_en, mtime, tamano, clasif_estado, detect_requerida, detect_estado)
            VALUES(:k, :full, :folder, :file, 1, :now, :now2, :mtime, :size, 'pending', 1, 'pending')
            ON CONFLICT(ruta_relativa) DO UPDATE SET
              ruta_completa=excluded.ruta_completa,
              ruta_carpeta=excluded.ruta_carpeta,
              archivo=excluded.archivo,
              indexada=1,
              actualizada_en=excluded.actualizada_en,
              mtime=excluded.mtime,
              tamano=excluded.tamano
        ");
        $stmtSetMd5 = $this->pdo->prepare("UPDATE images SET content_md5 = :m WHERE ruta_relativa = :k");

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

                $stmtCheck->execute([':k' => $rutaRelativa]);
                $exists = ($stmtCheck->fetchColumn() !== false);
                if ($exists) $existentes++; else $nuevas++;

                $stmtUpsert->execute([
                    ':k' => $rutaRelativa,
                    ':full' => $rutaRealpath,
                    ':folder' => $carpeta,
                    ':file' => basename($rutaRelativa),
                    ':now' => $ahora,
                    ':now2' => $ahora,
                    ':mtime' => @filemtime($rutaRealpath) ?: null,
                    ':size' => @filesize($rutaRealpath) ?: null
                ]);

                // Guardar MD5 si es posible (si hay duplicado, el índice único puede rechazarlo)
                $md5 = self::md5FileSafe($rutaRealpath);
                if ($md5) {
                    try {
                        $stmtSetMd5->execute([':m' => $md5, ':k' => $rutaRelativa]);
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

        $stmtFolder = $this->pdo->prepare("
            INSERT INTO folders(ruta_carpeta, nombre, search_key, total_imagenes, actualizada_en)
            VALUES(:ruta, :nombre, :search_key, (SELECT COUNT(*) FROM images WHERE ruta_carpeta = :ruta2), :now)
            ON CONFLICT(ruta_carpeta) DO UPDATE SET
              nombre = excluded.nombre,
              search_key = excluded.search_key,
              total_imagenes = (SELECT COUNT(*) FROM images WHERE ruta_carpeta = excluded.ruta_carpeta),
              actualizada_en = excluded.actualizada_en
        ");
        foreach (array_keys($foldersTouched) as $rutaCarpeta) {
            $nombre = ($rutaCarpeta === '') ? '' : basename($rutaCarpeta);
            $searchKey = StringNormalizer::toSearchKey($rutaCarpeta . ' ' . $nombre);
            $stmtFolder->execute([
                ':ruta' => $rutaCarpeta,
                ':ruta2' => $rutaCarpeta,
                ':nombre' => $nombre,
                ':search_key' => $searchKey,
                ':now' => $ahora
            ]);
        }

        return ['nuevas' => $nuevas, 'existentes' => $existentes, 'ignoradas' => $ignoradas];
    }

    /**
     * Devuelve [key, record, fase] donde fase ∈ {'clasificacion','deteccion'}
     */
    public function obtenerSiguientePendienteProcesar(): ?array
    {
        // Nuevo flujo: solo /detect. Tomar primero las pendientes por detect.
        $row = $this->pdo->query("
            SELECT *
            FROM images
            WHERE detect_estado = 'pending' OR detect_estado = 'na' OR detect_estado IS NULL
            ORDER BY ruta_relativa ASC
            LIMIT 1
        ")->fetch();
        if ($row) {
            $k = (string)$row['ruta_relativa'];
            return [$k, $this->rowToRecord($row), 'deteccion'];
        }

        // Compat: imágenes antiguas que hayan quedado en pending antes del cambio.
        $row = $this->pdo->query("SELECT * FROM images WHERE clasif_estado = 'pending' ORDER BY ruta_relativa ASC LIMIT 1")->fetch();
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
            UPDATE images
            SET clasif_estado='error', clasif_error=:e, clasif_en=:t, actualizada_en=:t2, resultado=NULL
            WHERE ruta_relativa = :k
        ");
        $stmt->execute([':e' => $mensaje, ':t' => $now, ':t2' => $now, ':k' => $key]);
    }

    public function marcarEmpty(string $key, string $mensaje = 'empty'): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("
            UPDATE images
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
            WHERE ruta_relativa = :k
        ");
        $stmt->execute([':e' => $mensaje, ':t' => $now, ':t2' => $now, ':k' => $key]);
    }

    public function marcarProcesada(string $key, float $safe, float $unsafe): void
    {
        $now = date('Y-m-d H:i:s');
        $resultado = $this->calcularResultado($safe, $unsafe);
        $detectRequerida = ($resultado === 'unsafe') ? 1 : 0;

        $stmt = $this->pdo->prepare("
            UPDATE images
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
            WHERE ruta_relativa=:k
        ");
        $stmt->execute([
            ':s' => $safe,
            ':u' => $unsafe,
            ':r' => $resultado,
            ':t' => $now,
            ':t2' => $now,
            ':dr' => $detectRequerida,
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
                UPDATE images
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

            $sql .= " WHERE ruta_relativa=:k";

            $upd = $this->pdo->prepare($sql);
            $upd->execute($params);

            $del = $this->pdo->prepare("DELETE FROM detections WHERE image_ruta_relativa = :k");
            $del->execute([':k' => $key]);

            $ins = $this->pdo->prepare("
                INSERT INTO detections(image_ruta_relativa, label, score, x1, y1, x2, y2)
                VALUES(:img, :label, :score, :x1, :y1, :x2, :y2)
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
            UPDATE images
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
            WHERE ruta_relativa=:k
        ");
        $stmt->execute([':e' => $mensaje, ':e2' => $mensaje, ':t' => $now, ':t2' => $now, ':t3' => $now, ':k' => $key]);
    }

    public function getEtiquetasDetectadas(bool $recalcularSiInconsistente = true): array
    {
        $rows = $this->pdo->query("
            SELECT label,
                   COUNT(DISTINCT image_ruta_relativa) as c,
                   MIN(score) as min_score,
                   MAX(score) as max_score
            FROM detections
            GROUP BY label
            ORDER BY c DESC, label ASC
        ")->fetchAll();
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

        $placeholders = implode(',', array_fill(0, count($labelsNorm), '?'));
        // Label normalizado: mayúsculas y espacios → _ (coincide aunque en BD esté "BELLY EXPOSED" o "belly_exposed")
        $labelCondition = "UPPER(REPLACE(TRIM(d.label), ' ', '_')) IN ($placeholders)";
        // Comparación directa por score (sin ROUND) como antes
        $stmtTop = $this->pdo->prepare("
            SELECT d.image_ruta_relativa, MAX(d.score) as best_score
            FROM detections d
            WHERE $labelCondition AND d.score >= ?
            GROUP BY d.image_ruta_relativa
            ORDER BY best_score DESC
            LIMIT " . self::MAX_RESULTADOS_BUSQUEDA_ETIQUETAS . "
        ");
        $stmtTop->execute(array_merge($labelsNorm, [$umbral]));
        $top = $stmtTop->fetchAll();
        if (empty($top)) return [];

        $imageKeys = array_map(fn($r) => (string)$r['image_ruta_relativa'], $top);
        $bestByKey = [];
        foreach ($top as $r) $bestByKey[(string)$r['image_ruta_relativa']] = (float)$r['best_score'];

        // Solo mantener imágenes que existan en el índice (evitar huérfanas)
        $ph2 = implode(',', array_fill(0, count($imageKeys), '?'));
        $stmtExists = $this->pdo->prepare("SELECT ruta_relativa FROM images WHERE ruta_relativa IN ($ph2)");
        $stmtExists->execute($imageKeys);
        $existingKeys = [];
        while ($row = $stmtExists->fetch(\PDO::FETCH_NUM)) {
            $existingKeys[(string)$row[0]] = true;
        }
        $imageKeys = array_values(array_filter($imageKeys, fn($k) => !empty($existingKeys[$k])));
        if (empty($imageKeys)) return [];

        $ph2 = implode(',', array_fill(0, count($imageKeys), '?'));
        $stmtInfo = $this->pdo->prepare("SELECT ruta_relativa, ruta_carpeta, archivo FROM images WHERE ruta_relativa IN ($ph2)");
        $stmtInfo->execute($imageKeys);
        $infoRows = $stmtInfo->fetchAll();
        $infoByKey = [];
        foreach ($infoRows as $r) $infoByKey[(string)$r['ruta_relativa']] = $r;

        $stmtMatches = $this->pdo->prepare("
            SELECT d.image_ruta_relativa, d.label, MAX(d.score) as score
            FROM detections d
            WHERE d.image_ruta_relativa IN ($ph2)
              AND UPPER(REPLACE(TRIM(d.label), ' ', '_')) IN ($placeholders)
              AND d.score >= ?
            GROUP BY d.image_ruta_relativa, d.label
        ");
        $stmtMatches->execute(array_merge($imageKeys, $labelsNorm, [$umbral]));
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

        if ($tipo === 'error' || $tipo === 'empty') {
            $stmt = $this->pdo->prepare("
                SELECT ruta_relativa, ruta_carpeta, archivo, clasif_estado, clasif_error, clasif_en
                FROM images
                WHERE clasif_estado = :st
                ORDER BY COALESCE(clasif_en, actualizada_en) DESC
                LIMIT 2000
            ");
            $stmt->execute([':st' => $tipo]);
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
            FROM images
            WHERE clasif_estado <> 'pending'
              AND {$field} > {$fieldOther}
              AND {$field} >= :u
            ORDER BY score DESC
            LIMIT 2000
        ");
        $stmt->execute([':u' => $umbral]);
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

