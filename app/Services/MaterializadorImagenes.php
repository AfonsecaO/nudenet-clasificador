<?php

namespace App\Services;

class MaterializadorImagenes
{
    private $directorioBase;
    private $patronMaterializacion;
    private $extensionesValidas = ['jpg', 'jpeg', 'png'];
    private $seenMd5 = [];
    /** Cache por request: compresión habilitada (null = no leído aún). */
    private $compressionEnabled = null;
    /** Cache por request: tamaño mínimo en bytes para comprimir (null = no leído aún, 0 = sin mínimo). */
    private $compressMinBytes = null;

    public function __construct()
    {
        // Directorio fijo por workspace
        $ws = WorkspaceService::current();
        if ($ws === null) {
            throw new \Exception("No hay workspace activo para materializar imágenes.");
        }
        WorkspaceService::ensureStructure($ws);
        $this->directorioBase = WorkspaceService::paths($ws)['imagesDir'];
        
        $this->patronMaterializacion = \App\Services\ConfigService::obtenerOpcional(
            'PATRON_MATERIALIZACION',
            '{{CAMPO_IDENTIFICADOR}}/{{CAMPO_IDENTIFICADOR}}_{{CAMPO_USR_ID}}_{{CAMPO_RESULTADO}}_{{CAMPO_FECHA}}.ext'
        );
        
        // Crear directorio base si no existe
        if (!is_dir($this->directorioBase)) {
            @mkdir($this->directorioBase, 0755, true);
            if (!is_dir($this->directorioBase)) {
                throw new \Exception("No se pudo crear el directorio base: " . $this->directorioBase);
            }
        }
        
        // Verificar permisos de escritura
        if (!is_writable($this->directorioBase)) {
            throw new \Exception("El directorio base no tiene permisos de escritura: " . $this->directorioBase);
        }
    }

    /**
     * Hash para dedupe: MD5 del dato directo (LONGTEXT base64 tal cual viene de la base).
     */
    private static function md5DatoDirecto(string $datoDirecto): string
    {
        return strtolower(md5($datoDirecto));
    }

    /**
     * Devuelve los MD5 que ya existen en el índice (content_md5 o raw_md5) para el workspace actual.
     * Una sola consulta UNION.
     */
    private function obtenerMd5ExistentesEnBatch(array $md5List): array
    {
        $md5List = array_values(array_unique(array_filter(array_map(function ($m) {
            $m = strtolower(trim((string) $m));
            return (strlen($m) === 32) ? $m : null;
        }, $md5List))));
        if (empty($md5List)) {
            return [];
        }
        $existentes = [];
        try {
            $pdo = AppConnection::get();
            AppSchema::ensure($pdo);
            $tImg = AppConnection::table('images');
            $ws = AppConnection::currentSlug() ?? 'default';
            $ph = implode(',', array_fill(0, count($md5List), '?'));
            $params = array_merge([$ws], $md5List, [$ws], $md5List);
            $sql = "SELECT content_md5 AS m FROM {$tImg} WHERE workspace_slug = ? AND content_md5 IN ({$ph}) " .
                   "UNION SELECT raw_md5 AS m FROM {$tImg} WHERE workspace_slug = ? AND raw_md5 IN ({$ph})";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            while (($row = $stmt->fetch(\PDO::FETCH_NUM)) !== false && isset($row[0]) && $row[0] !== '') {
                $existentes[$row[0]] = true;
            }
        } catch (\Throwable $e) {
            // Silencioso: no bloquear materialización
        }
        return $existentes;
    }

    /**
     * Comprueba si ya existe una imagen con este MD5 (content_md5 o raw_md5) en el workspace actual.
     * Usa seenMd5 (precargado en batch desde materializarImagenesRegistro) para evitar consultas extra.
     */
    private function yaExisteMd5(string $md5): bool
    {
        $md5 = strtolower(trim($md5));
        if (strlen($md5) !== 32) return false;

        // Dedupe rápido dentro del mismo registro/ejecución (incluye batch precargado)
        if (isset($this->seenMd5[$md5])) {
            return true;
        }

        // Dedupe global contra el índice (solo si no se precargó en batch)
        try {
            $pdo = AppConnection::get();
            AppSchema::ensure($pdo);
            $tImg = AppConnection::table('images');
            $ws = AppConnection::currentSlug() ?? 'default';
            $stmt = $pdo->prepare("SELECT 1 FROM {$tImg} WHERE workspace_slug = :ws AND (content_md5 = :m OR raw_md5 = :m2) LIMIT 1");
            $stmt->execute([':ws' => $ws, ':m' => $md5, ':m2' => $md5]);
            $exists = ($stmt->fetchColumn() !== false);
            if ($exists) return true;
        } catch (\Throwable $e) {
            // Silencioso: no bloquear materialización si SQLite no está disponible
        }

        return false;
    }

    /**
     * Verifica si una cadena es una imagen válida (base64 o binario)
     */
    private function esImagenValida($datos)
    {
        if (empty($datos)) {
            return false;
        }

        // 1) Data URI base64 (image/*)
        if (preg_match('/^data:image\/([a-zA-Z0-9.+-]+);base64,(.+)$/', $datos, $matches)) {
            $bin = base64_decode($matches[2], true);
            if ($bin === false) return false;
            $mime = $this->detectarMimeDesdeBinario($bin);
            return $mime !== null && strpos($mime, 'image/') === 0;
        }

        // 2) Binario directo (magic bytes / fileinfo)
        $mime = $this->detectarMimeDesdeBinario($datos);
        if ($mime !== null && strpos($mime, 'image/') === 0) {
            return true;
        }

        // 3) Base64 "crudo" (sin data URI): intentar decodificar y validar por MIME
        $bin = $this->intentarDecodeBase64Crudo($datos);
        if ($bin !== null) {
            $mime2 = $this->detectarMimeDesdeBinario($bin);
            return $mime2 !== null && strpos($mime2, 'image/') === 0;
        }

        return false;
    }

    /**
     * Intenta detectar si $datos parece base64 crudo y lo decodifica a binario.
     */
    private function intentarDecodeBase64Crudo($datos)
    {
        if (!is_string($datos)) return null;

        // Quitar espacios y saltos de línea comunes en base64
        $clean = preg_replace('/\s+/', '', $datos);
        if ($clean === null) return null;

        // Heurística: base64 suele ser bastante largo y con charset típico
        if (strlen($clean) < 64) return null;
        if (strlen($clean) % 4 !== 0) return null;
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $clean)) return null;

        $bin = base64_decode($clean, true);
        if ($bin === false || $bin === '') return null;
        return $bin;
    }

    /**
     * Detecta el MIME desde un string binario.
     */
    private function detectarMimeDesdeBinario($bin)
    {
        if (!is_string($bin) || $bin === '') return null;

        // 1) fileinfo (más general)
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = @finfo_buffer($finfo, $bin);
                @finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }

        // 2) getimagesizefromstring (fallback)
        if (function_exists('getimagesizefromstring')) {
            $info = @getimagesizefromstring($bin);
            if (is_array($info) && !empty($info['mime'])) {
                return $info['mime'];
            }
        }

        // 3) Fallback por magic bytes (limitado)
        $header4 = substr($bin, 0, 4);
        if (strpos($header4, "\xFF\xD8\xFF") === 0) return 'image/jpeg';
        if (strpos($header4, "\x89\x50\x4E\x47") === 0) return 'image/png';
        if (strpos($header4, "GIF8") === 0) return 'image/gif';
        if (strpos($header4, "RIFF") === 0) return 'image/webp';
        if (strpos($header4, "BM") === 0) return 'image/bmp';
        // HEIC/HEIF: ftyp at offset 4, brand heic/mif1/heix/hevc at 8
        if (strlen($bin) >= 12 && substr($bin, 4, 4) === 'ftyp') {
            $brand = substr($bin, 8, 4);
            if (in_array($brand, ['heic', 'mif1', 'heix', 'hevc'], true)) return 'image/heic';
        }

        return null;
    }

    /**
     * Deriva la extensión de archivo desde el tipo MIME (evita re-extraer binario cuando ya tenemos MIME).
     */
    private function extensionDesdeMime(string $mime): string
    {
        if (strpos($mime, 'image/') !== 0) {
            return 'jpg';
        }
        $sub = strtolower(substr($mime, strlen('image/')));
        if ($sub === 'jpeg') return 'jpg';
        if ($sub === 'svg+xml') return 'svg';
        if ($sub === 'x-icon') return 'ico';
        $sub = preg_replace('/[^a-z0-9]+/', '', $sub);
        return $sub !== '' ? $sub : 'jpg';
    }

    /**
     * Obtiene la extensión de la imagen
     */
    private function obtenerExtension($datos)
    {
        // Si es data URI, usar el subtipo si es simple; igual validamos con MIME al extraer.
        if (preg_match('/^data:image\/([a-zA-Z0-9.+-]+);base64,/', $datos, $matches)) {
            $sub = strtolower($matches[1]);
            // Normalizar subtipos comunes
            if ($sub === 'jpeg') return 'jpg';
            if ($sub === 'svg+xml') return 'svg';
            // Limpiar
            $sub = preg_replace('/[^a-z0-9]+/', '', $sub);
            if (!empty($sub)) return $sub;
        }

        $bin = $this->extraerDatosImagen($datos);
        $mime = $this->detectarMimeDesdeBinario($bin);
        if (is_string($mime) && strpos($mime, 'image/') === 0) {
            $sub = strtolower(substr($mime, strlen('image/')));
            if ($sub === 'jpeg') return 'jpg';
            if ($sub === 'svg+xml') return 'svg';
            if ($sub === 'x-icon') return 'ico';
            // Limpiar a extensión segura
            $sub = preg_replace('/[^a-z0-9]+/', '', $sub);
            if (!empty($sub)) return $sub;
        }

        return 'jpg';
    }

    /**
     * Extrae los datos binarios de la imagen
     */
    private function extraerDatosImagen($datos)
    {
        if (!is_string($datos)) {
            return $datos;
        }

        // Data URI base64
        if (preg_match('/^data:image\/[a-zA-Z0-9.+-]+;base64,(.+)$/', $datos, $matches)) {
            $bin = base64_decode($matches[1], true);
            return ($bin !== false) ? $bin : $datos;
        }

        // Base64 crudo
        $bin = $this->intentarDecodeBase64Crudo($datos);
        if ($bin !== null) {
            // Solo devolver si realmente es una imagen
            $mime = $this->detectarMimeDesdeBinario($bin);
            if ($mime !== null && strpos($mime, 'image/') === 0) {
                return $bin;
            }
        }

        // Binario directo
        return $datos;
    }

    /**
     * Indica si se debe comprimir la imagen según config (IMAGE_COMPRESSION_ENABLED, IMAGE_COMPRESS_MIN_BYTES).
     * Cache por request.
     */
    private function debeComprimirImagen(int $sizeBytes): bool
    {
        if ($this->compressionEnabled === null) {
            $v = ConfigService::obtenerOpcional('IMAGE_COMPRESSION_ENABLED', '1');
            $this->compressionEnabled = ($v !== null && $v !== '' && $v !== '0' && strtolower($v) !== 'false' && strtolower($v) !== 'no');
        }
        if (!$this->compressionEnabled) {
            return false;
        }
        if ($this->compressMinBytes === null) {
            $v = ConfigService::obtenerOpcional('IMAGE_COMPRESS_MIN_BYTES', '0');
            $this->compressMinBytes = ($v !== null && $v !== '') ? max(0, (int) $v) : 0;
        }
        return $sizeBytes >= $this->compressMinBytes;
    }

    /**
     * Construye el nombre base para una imagen del registro (identificador_usrId_contador, normalizado).
     */
    private function nombreBaseParaRegistro(string $identificador, string $usrId, int $contador): string
    {
        $nombreBase = trim($identificador) . '_' . trim($usrId) . '_' . $contador;
        $nombreBase = preg_replace('/\s+/', '_', $nombreBase);
        $nombreBase = preg_replace('/_+/', '_', $nombreBase);
        return trim($nombreBase, '_');
    }

    /**
     * Genera un nombre único para el archivo, agregando secuencia si ya existe
     */
    private function generarNombreUnico($directorio, $nombreBase, $extension)
    {
        // Asegurar que nombreBase y extension estén limpios
        $nombreBase = trim($nombreBase);
        $extension = trim($extension);
        
        // Limpiar nombreBase: eliminar espacios y caracteres no válidos
        $nombreBase = preg_replace('/\s+/', '_', $nombreBase);
        $nombreBase = preg_replace('/[^a-zA-Z0-9_-]/', '', $nombreBase);
        $nombreBase = preg_replace('/_+/', '_', $nombreBase);
        $nombreBase = trim($nombreBase, '_');
        
        $nombreArchivo = $nombreBase . '.' . $extension;
        $rutaCompleta = $directorio . '/' . $nombreArchivo;
        
        // Si el archivo no existe, usar el nombre original
        if (!file_exists($rutaCompleta)) {
            return $nombreArchivo;
        }
        
        // Si existe, agregar secuencia numérica
        $contador = 1;
        do {
            $nombreArchivo = $nombreBase . '_' . $contador . '.' . $extension;
            $rutaCompleta = $directorio . '/' . $nombreArchivo;
            $contador++;
        } while (file_exists($rutaCompleta));
        
        return $nombreArchivo;
    }

    /**
     * Reemplaza las variables del patrón con los valores reales
     */
    private function reemplazarVariablesPatron($patron, $identificador, $usrId, $resultado, $fechaCreacion, $extension)
    {
        // Formatear fecha como año_mes_dia
        $fechaFormato = '';
        if ($fechaCreacion) {
            $fechaCreacion = trim($fechaCreacion);
            $timestamp = is_numeric($fechaCreacion) ? $fechaCreacion : strtotime($fechaCreacion);
            if ($timestamp !== false) {
                $fechaFormato = date('Y_m_d', $timestamp);
            } else {
                $fechaFormato = date('Y_m_d');
            }
        } else {
            $fechaFormato = date('Y_m_d');
        }
        
        // Limpiar valores para usar en rutas y nombres de archivo
        $identificador = trim($identificador);
        $identificador = preg_replace('/[^a-zA-Z0-9_-]/', '', $identificador);
        
        $usrId = trim($usrId);
        $usrId = preg_replace('/[^a-zA-Z0-9_-]/', '', $usrId);
        
        $resultado = trim($resultado);
        $resultado = preg_replace('/[^a-zA-Z0-9_-]/', '', $resultado);
        if (empty($resultado)) {
            $resultado = 'sin_resultado';
        }
        
        // Reemplazar variables en el patrón
        $patron = str_replace('{{CAMPO_IDENTIFICADOR}}', $identificador, $patron);
        $patron = str_replace('{{CAMPO_USR_ID}}', $usrId, $patron);
        $patron = str_replace('{{CAMPO_RESULTADO}}', $resultado, $patron);
        $patron = str_replace('{{CAMPO_FECHA}}', $fechaFormato, $patron);
        // Extensión solo al materializar: si el patrón tiene .ext (config antigua), reemplazar; si no, añadir al final
        if (stripos($patron, '.ext') !== false) {
            $patron = str_replace('.ext', '.' . $extension, $patron);
        } else {
            $patron .= '.' . $extension;
        }
        return $patron;
    }
    
    /**
     * Materializa una imagen desde datos de base de datos.
     * Mantiene la firma pública para compatibilidad (Upload, tests). Resuelve binario+MIME y delega en materializarImagenDesdeBinario.
     */
    public function materializarImagen($datosImagen, $nombreBase, $fechaCreacion = null, $identificador = '', $resultado = '', $usrId = '')
    {
        if (!$this->esImagenValida($datosImagen)) {
            return [
                'success' => false,
                'error' => 'Los datos no son una imagen válida'
            ];
        }
        $bin = $this->extraerDatosImagen($datosImagen);
        $mime = $this->detectarMimeDesdeBinario($bin);
        if ($mime === null || strpos($mime, 'image/') !== 0) {
            return [
                'success' => false,
                'error' => 'Formato no reconocido. Solo se permiten JPG, JPEG, PNG y HEIC (convertido a JPG).'
            ];
        }
        $hashDedupe = is_string($datosImagen) && $datosImagen !== ''
            ? self::md5DatoDirecto($datosImagen)
            : strtolower(md5($bin));
        return $this->materializarImagenDesdeBinario(
            ['bin' => $bin, 'mime' => $mime, 'hash_dedupe' => $hashDedupe],
            $nombreBase,
            $fechaCreacion,
            $identificador,
            $resultado,
            $usrId
        );
    }

    /**
     * Materializa una imagen desde binario y MIME ya extraídos (evita decodificación y detección MIME repetidos).
     * Flujo por fases: validación/dedupe raw → formato y conversión → ruta y nombre → comprimir/dedupe content → escritura.
     */
    private function materializarImagenDesdeBinario(array $binYMeta, string $nombreBase, $fechaCreacion = null, $identificador = '', $resultado = '', $usrId = '')
    {
        $datosBinariosRaw = $binYMeta['bin'];
        $mime = $binYMeta['mime'] ?? null;

        try {
            // — Fase 1: Dedupe por MD5(dato directo de la base) —
            $rawMd5 = ($binYMeta['hash_dedupe'] ?? '') !== ''
                ? $binYMeta['hash_dedupe']
                : strtolower(md5($datosBinariosRaw));
            if ($this->yaExisteMd5($rawMd5)) {
                $this->seenMd5[$rawMd5] = true;
                return [
                    'success' => true,
                    'duplicate' => true,
                    'md5' => $rawMd5,
                    'raw_md5' => $rawMd5,
                    'mensaje' => 'Imagen duplicada (MD5 dato directo). Omitida.'
                ];
            }
            $this->seenMd5[$rawMd5] = true;

            // — Fase 2: Formato y conversión (MIME, HEIC→JPG si aplica, extensión final) —
            $datosBinarios = $datosBinariosRaw;
            $datosBinariosRaw = null;
            if ($mime === null || strpos($mime, 'image/') !== 0) {
                return [
                    'success' => false,
                    'error' => 'Formato no reconocido. Solo se permiten JPG, JPEG, PNG y HEIC (convertido a JPG).'
                ];
            }
            if (HeicConverter::isHeicMime($mime)) {
                $jpgBinary = HeicConverter::convertBinaryToJpg($datosBinarios);
                if ($jpgBinary === null) {
                    return [
                        'success' => false,
                        'error' => 'No se pudo convertir HEIC a JPG. Instale Imagick con soporte HEIC o ImageMagick/heif-convert.'
                    ];
                }
                $datosBinarios = $jpgBinary;
                $extension = 'jpg';
            } elseif ($mime === 'image/jpeg' || $mime === 'image/png') {
                $extension = $this->extensionDesdeMime($mime);
                if ($extension === 'jpeg') $extension = 'jpg';
                if (!in_array($extension, ['jpg', 'png'], true)) $extension = ($mime === 'image/png') ? 'png' : 'jpg';
            } else {
                return [
                    'success' => false,
                    'error' => 'Formato no aceptado. Solo se permiten JPG, JPEG, PNG y HEIC (convertido a JPG).'
                ];
            }

            // — Fase 3: Ruta y nombre (reemplazarVariablesPatron, directorio, generarNombreUnico) —
            $usrId = trim($usrId);
            $rutaCompleta = $this->reemplazarVariablesPatron(
                $this->patronMaterializacion,
                $identificador,
                $usrId,
                $resultado,
                $fechaCreacion,
                $extension
            );
            $rutaCompleta = $this->directorioBase . '/' . $rutaCompleta;
            $relativa = str_replace($this->directorioBase . '/', '', $rutaCompleta);
            $relativa = StringNormalizer::normalizeRelativePath($relativa);
            if ($relativa !== '') {
                $rutaCompleta = $this->directorioBase . '/' . $relativa;
            }

            $directorioDestino = dirname($rutaCompleta);
            $nombreArchivo = basename($rutaCompleta);

            if (!is_dir($directorioDestino)) {
                $creado = @mkdir($directorioDestino, 0755, true);
                if (!$creado && !is_dir($directorioDestino)) {
                    return [
                        'success' => false,
                        'error' => 'No se pudo crear el directorio: ' . $directorioDestino . '. Verifique los permisos.'
                    ];
                }
            }

            $nombreArchivoSinExt = pathinfo($nombreArchivo, PATHINFO_FILENAME);
            $extensionArchivo = pathinfo($nombreArchivo, PATHINFO_EXTENSION);
            $nombreArchivoFinal = $this->generarNombreUnico($directorioDestino, $nombreArchivoSinExt, $extensionArchivo);
            $rutaCompleta = $directorioDestino . '/' . $nombreArchivoFinal;

            // — Fase 4: Comprimir y dedupe content (ImageCompressor, content MD5, salida temprana si duplicado) —
            $toSave = $datosBinarios;
            if ($this->debeComprimirImagen(strlen($datosBinarios))) {
                $compressed = ImageCompressor::compress($datosBinarios, $extensionArchivo);
                if ($compressed !== null) {
                    $toSave = $compressed;
                }
            }
            $datosBinarios = null; // Liberar referencia; ya no se necesita

            $md5 = strtolower(md5($toSave));
            if (isset($this->seenMd5[$md5])) {
                return [
                    'success' => true,
                    'duplicate' => true,
                    'md5' => $md5,
                    'raw_md5' => $rawMd5,
                    'mensaje' => 'Imagen duplicada (MD5 archivo). Omitida.'
                ];
            }
            $this->seenMd5[$md5] = true;

            // — Fase 5: Escritura —
            $escrito = @file_put_contents($rutaCompleta, $toSave);
            $toSave = null;

            if ($escrito === false) {
                return [
                    'success' => false,
                    'error' => 'No se pudo escribir el archivo: ' . $rutaCompleta . '. Verifique los permisos.'
                ];
            }

            return [
                'success' => true,
                'ruta' => $rutaCompleta,
                'nombre' => $nombreArchivo,
                'directorio' => $directorioDestino,
                'md5' => $md5,
                'raw_md5' => $rawMd5
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Materializa todas las imágenes de un registro.
     * Precarga seenMd5 con un batch de MD5 existentes en BD para reducir consultas de dedupe.
     */
    public function materializarImagenesRegistro($registro, $columnasImagen, $campoIdentificador, $campoUsrId, $campoFecha, $campoResultado = null)
    {
        $resultados = [];
        $this->seenMd5 = [];

        // Hash de dedupe = MD5 del dato directo de la base (LONGTEXT base64 tal cual viene).
        // para que varias columnas con el mismo nombre o varias “ranuras” no se sobrescriban y todas se materialicen.
        $rawMd5List = [];
        $binYMetaPorIndice = [];
        foreach ($columnasImagen as $idx => $columna) {
            $datoDirecto = $registro[$columna] ?? '';
            if ($datoDirecto === '' || !$this->esImagenValida($datoDirecto)) {
                continue;
            }
            $bin = $this->extraerDatosImagen($datoDirecto);
            if (!is_string($bin) || $bin === '') {
                continue;
            }
            $mime = $this->detectarMimeDesdeBinario($bin);
            $hashDedupe = self::md5DatoDirecto(is_string($datoDirecto) ? $datoDirecto : $bin);
            $rawMd5List[] = $hashDedupe;
            $binYMetaPorIndice[$idx] = ['bin' => $bin, 'mime' => $mime, 'hash_dedupe' => $hashDedupe];
        }
        if (!empty($rawMd5List)) {
            $existentes = $this->obtenerMd5ExistentesEnBatch($rawMd5List);
            foreach ($existentes as $m => $_) {
                $this->seenMd5[$m] = true;
            }
        }
        
        // Aplicar trim a todas las variables
        $identificador = trim($registro[$campoIdentificador] ?? '');
        $usrId = trim($registro[$campoUsrId] ?? '');
        $fechaCreacion = $registro[$campoFecha] ?? null;
        if ($fechaCreacion) {
            $fechaCreacion = trim($fechaCreacion);
        }
        
        // Obtener el valor del campo resultado
        $resultado = '';
        if ($campoResultado && isset($registro[$campoResultado])) {
            $resultado = trim($registro[$campoResultado]);
        }
        
        // Si el resultado está vacío, usar un valor por defecto
        if (empty($resultado)) {
            $resultado = 'sin_resultado';
        }
        
        // Limpiar identificador y resultado para usar en rutas (eliminar caracteres no válidos)
        $identificador = preg_replace('/[^a-zA-Z0-9_-]/', '', $identificador);
        $resultado = preg_replace('/[^a-zA-Z0-9_-]/', '', $resultado);
        
        $contador = 1;
        foreach ($columnasImagen as $idx => $columna) {
            $datoCelda = $registro[$columna] ?? '';
            if ($datoCelda === '') {
                $resultados[$idx] = ['success' => false, 'error' => 'Campo vacío'];
                continue;
            }
            $nombreBase = $this->nombreBaseParaRegistro($identificador, $usrId, $contador);
            $resultados[$idx] = isset($binYMetaPorIndice[$idx])
                ? $this->materializarImagenDesdeBinario(
                    $binYMetaPorIndice[$idx],
                    $nombreBase,
                    $fechaCreacion,
                    $identificador,
                    $resultado,
                    $usrId
                )
                : $this->materializarImagen($registro[$columna], $nombreBase, $fechaCreacion, $identificador, $resultado, $usrId);
            $contador++;
        }
        
        return $resultados;
    }
}
