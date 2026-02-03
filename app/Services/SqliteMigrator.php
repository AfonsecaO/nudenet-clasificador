<?php

namespace App\Services;

use PDO;

class SqliteMigrator
{
    /**
     * Bootstrap automático:
     * - Si la DB SQLite existe y migration_done=1 => no hace nada
     * - Si no existe y hay JSON => migra una sola vez
     */
    public static function bootstrap(): void
    {
        $sqlitePath = SqliteConnection::path();
        $dbExists = file_exists($sqlitePath);

        $pdo = SqliteConnection::get();
        SqliteSchema::ensure($pdo);

        $done = SqliteSchema::metaGet($pdo, 'migration_done', '0');
        if ($done === '1') {
            // Post-migración: asegurar folders si aún no se han construido
            self::ensureFoldersIndex($pdo);
            return;
        }

        if ($dbExists) {
            SqliteSchema::metaSet($pdo, 'migration_done', '1');
            return;
        }
    }

    private static function ensureFoldersIndex(PDO $pdo): void
    {
        $built = SqliteSchema::metaGet($pdo, 'folders_built', '0');
        $countFolders = (int)$pdo->query("SELECT COUNT(*) FROM folders")->fetchColumn();

        // Si ya está construido pero hay nombres vacíos, reconstruir (fix)
        if ($built === '1' && $countFolders > 0) {
            $hayVacios = (int)$pdo->query("SELECT COUNT(*) FROM folders WHERE COALESCE(nombre,'') = ''")->fetchColumn();
            if ($hayVacios === 0) {
                return;
            }
        }

        $countImages = (int)$pdo->query("SELECT COUNT(*) FROM images")->fetchColumn();
        if ($countImages === 0) {
            // Nada que construir
            SqliteSchema::metaSet($pdo, 'folders_built', '1');
            return;
        }

        $ahora = date('Y-m-d H:i:s');
        $pdo->beginTransaction();
        try {
            $pdo->exec("DELETE FROM folders");

            $rows = $pdo->query("
                SELECT COALESCE(ruta_carpeta, '') as ruta, COUNT(*) as total
                FROM images
                GROUP BY COALESCE(ruta_carpeta, '')
            ")->fetchAll();

            $stmt = $pdo->prepare("
                INSERT INTO folders(ruta_carpeta, nombre, search_key, total_imagenes, actualizada_en)
                VALUES(:ruta, :nombre, :search_key, :total, :t)
                ON CONFLICT(ruta_carpeta) DO UPDATE SET
                  nombre=excluded.nombre,
                  search_key=excluded.search_key,
                  total_imagenes=excluded.total_imagenes,
                  actualizada_en=excluded.actualizada_en
            ");

            foreach ($rows as $r) {
                $ruta = (string)($r['ruta'] ?? '');
                $total = (int)($r['total'] ?? 0);
                $nombre = ($ruta === '') ? '' : self::basenameRuta($ruta);
                $searchKey = StringNormalizer::toSearchKey($ruta . ' ' . $nombre);
                $stmt->execute([
                    ':ruta' => $ruta,
                    ':nombre' => $nombre,
                    ':search_key' => $searchKey,
                    ':total' => $total,
                    ':t' => $ahora
                ]);
            }
            $pdo->commit();
            SqliteSchema::metaSet($pdo, 'folders_built', '1');
        } catch (\Throwable $e) {
            $pdo->rollBack();
            // No bloquear la app por este rebuild; se puede intentar luego
        }
    }

    private static function basenameRuta(string $ruta): string
    {
        $ruta = str_replace('\\', '/', $ruta);
        $ruta = trim($ruta, '/');
        if ($ruta === '') return '';
        return basename($ruta);
    }

    private static function migrateAll(PDO $pdo, string $jsonTablas, string $jsonImagenes, string $jsonCarpetas): void
    {
        // Acelerar bulk load
        SqliteConnection::tuneForBulkLoad($pdo);

        $pdo->beginTransaction();
        try {
            if (file_exists($jsonTablas)) {
                self::migrateTablas($pdo, $jsonTablas);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Imágenes es grande: migrar en transacciones por bloques
        if (file_exists($jsonImagenes)) {
            self::migrateImagenesStreaming($pdo, $jsonImagenes);
        }

        // Carpetas: no es crítico; se puede reconstruir desde images más adelante.
        // Guardar timestamp en meta si existe.
        if (file_exists($jsonCarpetas)) {
            SqliteSchema::metaSet($pdo, 'carpetas_json_present', '1');
        }

        SqliteConnection::tuneForRuntime($pdo);
    }

    private static function migrateTablas(PDO $pdo, string $path): void
    {
        $raw = @file_get_contents($path);
        $json = json_decode($raw ?: '', true);
        if (!is_array($json)) return;

        $estado = $json['estado'] ?? [];
        $indice = $json['indice_tablas'] ?? null;

        // tables_state
        $stmt = $pdo->prepare("
            INSERT INTO tables_state(tabla, ultimo_id, max_id, faltan_registros, ultima_actualizacion, ultima_actualizacion_contador)
            VALUES(:tabla, :ultimo_id, :max_id, :faltan, :ua, :uac)
            ON CONFLICT(tabla) DO UPDATE SET
              ultimo_id=excluded.ultimo_id,
              max_id=excluded.max_id,
              faltan_registros=excluded.faltan_registros,
              ultima_actualizacion=excluded.ultima_actualizacion,
              ultima_actualizacion_contador=excluded.ultima_actualizacion_contador
        ");

        if (is_array($estado)) {
            foreach ($estado as $tabla => $st) {
                if (!is_string($tabla)) continue;
                $st = is_array($st) ? $st : [];
                $stmt->execute([
                    ':tabla' => $tabla,
                    ':ultimo_id' => (int)($st['ultimo_id'] ?? 0),
                    ':max_id' => (int)($st['max_id'] ?? 0),
                    ':faltan' => (($st['faltan_registros'] ?? true) !== false) ? 1 : 0,
                    ':ua' => $st['ultima_actualizacion'] ?? null,
                    ':uac' => $st['ultima_actualizacion_contador'] ?? null
                ]);
            }
        }

        // tables_index + meta
        if (is_array($indice)) {
            if (isset($indice['pattern'])) {
                SqliteSchema::metaSet($pdo, 'tables_pattern', (string)$indice['pattern']);
            }
            if (isset($indice['actualizada_en'])) {
                SqliteSchema::metaSet($pdo, 'tables_index_updated_at', (string)$indice['actualizada_en']);
            }
            $tablas = $indice['tablas'] ?? [];
            if (is_array($tablas)) {
                $pdo->exec("DELETE FROM tables_index");
                $stmt2 = $pdo->prepare("
                    INSERT INTO tables_index(tabla, max_id) VALUES(:tabla, :max_id)
                    ON CONFLICT(tabla) DO UPDATE SET max_id=excluded.max_id
                ");
                foreach ($tablas as $t) {
                    if (!is_array($t)) continue;
                    $tabla = (string)($t['tabla'] ?? '');
                    if ($tabla === '') continue;
                    $stmt2->execute([
                        ':tabla' => $tabla,
                        ':max_id' => (int)($t['max_id'] ?? 0)
                    ]);
                }
            }
        }
    }

    private static function migrateImagenesStreaming(PDO $pdo, string $path): void
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) return;

        // Preparar statements
        $stmtImg = $pdo->prepare("
            INSERT INTO images(
              ruta_relativa, ruta_completa, ruta_carpeta, archivo,
              indexada, indexada_en, actualizada_en, mtime, tamano,
              clasif_estado, safe, unsafe, resultado, clasif_error, clasif_en,
              detect_requerida, detect_estado, detect_error, detect_en
            ) VALUES(
              :ruta_relativa, :ruta_completa, :ruta_carpeta, :archivo,
              :indexada, :indexada_en, :actualizada_en, :mtime, :tamano,
              :clasif_estado, :safe, :unsafe, :resultado, :clasif_error, :clasif_en,
              :detect_requerida, :detect_estado, :detect_error, :detect_en
            )
            ON CONFLICT(ruta_relativa) DO UPDATE SET
              ruta_completa=excluded.ruta_completa,
              ruta_carpeta=excluded.ruta_carpeta,
              archivo=excluded.archivo,
              indexada=excluded.indexada,
              indexada_en=excluded.indexada_en,
              actualizada_en=excluded.actualizada_en,
              mtime=excluded.mtime,
              tamano=excluded.tamano,
              clasif_estado=excluded.clasif_estado,
              safe=excluded.safe,
              unsafe=excluded.unsafe,
              resultado=excluded.resultado,
              clasif_error=excluded.clasif_error,
              clasif_en=excluded.clasif_en,
              detect_requerida=excluded.detect_requerida,
              detect_estado=excluded.detect_estado,
              detect_error=excluded.detect_error,
              detect_en=excluded.detect_en
        ");

        $stmtDet = $pdo->prepare("
            INSERT INTO detections(image_ruta_relativa, label, score, x1, y1, x2, y2)
            VALUES(:img, :label, :score, :x1, :y1, :x2, :y2)
        ");

        // Limpiar detections para evitar duplicados
        $pdo->exec("DELETE FROM detections");

        $inImagenes = false;
        $reading = false;
        $key = null;
        $buf = '';
        $depth = 0;
        $inStr = false;
        $esc = false;

        $batch = 0;
        $pdo->beginTransaction();

        while (($line = fgets($fh)) !== false) {
            if (!$inImagenes) {
                if (strpos($line, '"imagenes"') !== false) {
                    $inImagenes = true;
                }
                continue;
            }

            if (!$reading) {
                if (preg_match('/^\s*"([^"]+)"\s*:\s*\{\s*$/', $line, $m)) {
                    $key = json_decode('"' . $m[1] . '"');
                    $reading = true;
                    $buf = "{\n";
                    $depth = 1;
                    $inStr = false;
                    $esc = false;
                    continue;
                }
                // Fin del objeto imagenes
                if (preg_match('/^\s*}\s*,?\s*$/', $line)) {
                    break;
                }
                continue;
            }

            $buf .= $line;
            self::updateDepth($line, $depth, $inStr, $esc);
            if ($depth === 0) {
                // Quitar coma final si existe
                $jsonText = trim($buf);
                $jsonText = preg_replace('/}\s*,\s*$/', "}", $jsonText);
                $record = json_decode($jsonText, true);
                if (is_array($record) && is_string($key) && $key !== '') {
                    self::insertImageRecord($stmtImg, $stmtDet, $key, $record);
                }

                $reading = false;
                $key = null;
                $buf = '';
                $batch++;

                if ($batch % 2000 === 0) {
                    $pdo->commit();
                    $pdo->beginTransaction();
                }
            }
        }

        $pdo->commit();
        fclose($fh);

        // Guardar info meta
        SqliteSchema::metaSet($pdo, 'images_migrated_at', date('Y-m-d H:i:s'));
    }

    private static function insertImageRecord($stmtImg, $stmtDet, string $rutaRelativa, array $r): void
    {
        $procesada = (bool)($r['procesada'] ?? false);
        $safe = isset($r['safe']) && is_numeric($r['safe']) ? (float)$r['safe'] : null;
        $unsafe = isset($r['unsafe']) && is_numeric($r['unsafe']) ? (float)$r['unsafe'] : null;
        $resultado = $r['resultado'] ?? null;
        if ($resultado !== 'safe' && $resultado !== 'unsafe') $resultado = null;

        $clasifEstado = 'pending';
        $clasifError = $r['error_ultimo'] ?? null;
        $clasifEn = $r['procesada_en'] ?? null;
        if ($procesada) {
            $clasifEstado = ($clasifError) ? 'error' : 'ok';
        }

        $detectRequerida = (($r['deteccion_requerida'] ?? false) === true) ? 1 : 0;
        $detectProcesada = (($r['deteccion_procesada'] ?? false) === true);
        $detectError = $r['deteccion_error_ultimo'] ?? null;
        $detectEn = $r['deteccion_en'] ?? null;

        $detectEstado = 'na';
        if ($detectRequerida) {
            if ($detectProcesada) $detectEstado = 'ok';
            elseif ($detectError) $detectEstado = 'error';
            else $detectEstado = 'pending';
        }

        $stmtImg->execute([
            ':ruta_relativa' => $rutaRelativa,
            ':ruta_completa' => $r['ruta_completa'] ?? null,
            ':ruta_carpeta' => $r['ruta_carpeta'] ?? null,
            ':archivo' => $r['archivo'] ?? null,
            ':indexada' => (($r['indexada'] ?? true) !== false) ? 1 : 0,
            ':indexada_en' => $r['indexada_en'] ?? null,
            ':actualizada_en' => $r['actualizada_en'] ?? null,
            ':mtime' => isset($r['mtime']) ? (int)$r['mtime'] : null,
            ':tamano' => isset($r['tamano']) ? (int)$r['tamano'] : null,
            ':clasif_estado' => $clasifEstado,
            ':safe' => $safe,
            ':unsafe' => $unsafe,
            ':resultado' => $resultado,
            ':clasif_error' => $clasifError,
            ':clasif_en' => $clasifEn,
            ':detect_requerida' => $detectRequerida,
            ':detect_estado' => $detectEstado,
            ':detect_error' => $detectError,
            ':detect_en' => $detectEn
        ]);

        // Detecciones
        $detecciones = $r['detecciones'] ?? null;
        if (is_array($detecciones)) {
            foreach ($detecciones as $d) {
                if (!is_array($d)) continue;
                $label = isset($d['label']) ? strtoupper(trim((string)$d['label'])) : '';
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

                $stmtDet->execute([
                    ':img' => $rutaRelativa,
                    ':label' => $label,
                    ':score' => $score,
                    ':x1' => $x1,
                    ':y1' => $y1,
                    ':x2' => $x2,
                    ':y2' => $y2
                ]);
            }
        }
    }

    private static function updateDepth(string $s, int &$depth, bool &$inStr, bool &$esc): void
    {
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($inStr) {
                if ($esc) {
                    $esc = false;
                    continue;
                }
                if ($ch === '\\') {
                    $esc = true;
                    continue;
                }
                if ($ch === '"') {
                    $inStr = false;
                }
                continue;
            }

            if ($ch === '"') {
                $inStr = true;
                continue;
            }
            if ($ch === '{') $depth++;
            if ($ch === '}') $depth--;
        }
    }
}

