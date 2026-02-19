<?php

namespace App\Services;

class MaterializadorImagenes
{
    private $directorioBase;
    private $patronMaterializacion;
    // Solo se aceptan JPG, JPEG, PNG y HEIC (convertido a JPG).
    private $extensionesValidas = ['jpg', 'jpeg', 'png'];
    private $seenMd5 = [];

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
     * Comprueba si ya existe una imagen con este MD5 (content_md5 o raw_md5) en el workspace actual.
     */
    private function yaExisteMd5(string $md5): bool
    {
        $md5 = strtolower(trim($md5));
        if (strlen($md5) !== 32) return false;

        // Dedupe rápido dentro del mismo registro/ejecución
        if (isset($this->seenMd5[$md5])) {
            return true;
        }

        // Dedupe global contra el índice (content_md5 del archivo o raw_md5 de la imagen tal cual vino de la BD)
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
     * Primer paso: dedupe con la imagen tal cual viene de la BD (raw). Si pasa, continúa materialización y optimización.
     */
    public function materializarImagen($datosImagen, $nombreBase, $fechaCreacion = null, $identificador = '', $resultado = '', $usrId = '')
    {
        if (!$this->esImagenValida($datosImagen)) {
            return [
                'success' => false,
                'error' => 'Los datos no son una imagen válida'
            ];
        }

        try {
            // 1) Extraer binario tal cual viene de la BD (antes de conversión/compresión)
            $datosBinariosRaw = $this->extraerDatosImagen($datosImagen);
            $rawMd5 = md5($datosBinariosRaw);
            $rawMd5 = strtolower($rawMd5);

            // 2) Primer proceso: dedupe con la imagen raw. Si ya existe, omitir sin materializar ni optimizar.
            if ($this->yaExisteMd5($rawMd5)) {
                $this->seenMd5[$rawMd5] = true;
                return [
                    'success' => true,
                    'duplicate' => true,
                    'md5' => $rawMd5,
                    'raw_md5' => $rawMd5,
                    'mensaje' => 'Imagen duplicada (MD5 raw). Omitida.'
                ];
            }
            $this->seenMd5[$rawMd5] = true;

            // 3) Continuar con formato, conversión HEIC y validación
            $datosBinarios = $datosBinariosRaw;
            $mime = $this->detectarMimeDesdeBinario($datosBinarios);
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
                $extension = $this->obtenerExtension($datosImagen);
                if ($extension === 'jpeg') $extension = 'jpg';
                if (!in_array($extension, ['jpg', 'png'], true)) $extension = ($mime === 'image/png') ? 'png' : 'jpg';
            } else {
                return [
                    'success' => false,
                    'error' => 'Formato no aceptado. Solo se permiten JPG, JPEG, PNG y HEIC (convertido a JPG).'
                ];
            }
            
            // Limpiar usrId
            $usrId = trim($usrId);
            
            // Reemplazar variables en el patrón
            $rutaCompleta = $this->reemplazarVariablesPatron(
                $this->patronMaterializacion,
                $identificador,
                $usrId,
                $resultado,
                $fechaCreacion,
                $extension
            );
            
            // Construir ruta completa desde el directorio base
            $rutaCompleta = $this->directorioBase . '/' . $rutaCompleta;
            // Normalizar cada segmento de la ruta (nombres sin caracteres extraños)
            $relativa = str_replace($this->directorioBase . '/', '', $rutaCompleta);
            $relativa = StringNormalizer::normalizeRelativePath($relativa);
            if ($relativa !== '') {
                $rutaCompleta = $this->directorioBase . '/' . $relativa;
            }

            // Separar directorio y nombre de archivo
            $directorioDestino = dirname($rutaCompleta);
            $nombreArchivo = basename($rutaCompleta);
            
            // Crear directorio si no existe
            if (!is_dir($directorioDestino)) {
                $creado = @mkdir($directorioDestino, 0755, true);
                if (!$creado && !is_dir($directorioDestino)) {
                    return [
                        'success' => false,
                        'error' => 'No se pudo crear el directorio: ' . $directorioDestino . '. Verifique los permisos.'
                    ];
                }
            }
            
            // Verificar si el archivo ya existe y generar nombre único con secuencia si es necesario
            $nombreArchivoSinExt = pathinfo($nombreArchivo, PATHINFO_FILENAME);
            $extensionArchivo = pathinfo($nombreArchivo, PATHINFO_EXTENSION);
            $nombreArchivoFinal = $this->generarNombreUnico($directorioDestino, $nombreArchivoSinExt, $extensionArchivo);
            $rutaCompleta = $directorioDestino . '/' . $nombreArchivoFinal;

            // Comprimir imagen (jpg/png) para reducir peso manteniendo extensión
            $toSave = $datosBinarios;
            $compressed = ImageCompressor::compress($datosBinarios, $extensionArchivo);
            if ($compressed !== null) {
                $toSave = $compressed;
            }

            // Calcular MD5 del archivo a guardar (por si el mismo contenido comprimido ya existía)
            $md5 = md5($toSave);
            if ($this->yaExisteMd5($md5)) {
                return [
                    'success' => true,
                    'duplicate' => true,
                    'md5' => $md5,
                    'raw_md5' => $rawMd5,
                    'mensaje' => 'Imagen duplicada (MD5 archivo). Omitida.'
                ];
            }
            $this->seenMd5[$md5] = true;

            // Guardar datos
            $escrito = @file_put_contents($rutaCompleta, $toSave);
            
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
     * Materializa todas las imágenes de un registro
     */
    public function materializarImagenesRegistro($registro, $columnasImagen, $campoIdentificador, $campoUsrId, $campoFecha, $campoResultado = null)
    {
        $resultados = [];
        $this->seenMd5 = [];
        
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
        
        // Contador para numerar las imágenes
        $contador = 1;
        
        foreach ($columnasImagen as $columna) {
            if (isset($registro[$columna]) && !empty($registro[$columna])) {
                // El nombreBase ya no se usa directamente, pero lo mantenemos para compatibilidad
                $nombreBase = trim($identificador) . '_' . trim($usrId) . '_' . $contador;
                $nombreBase = preg_replace('/\s+/', '_', $nombreBase);
                $nombreBase = preg_replace('/_+/', '_', $nombreBase);
                $nombreBase = trim($nombreBase, '_');
                
                $resultadoImg = $this->materializarImagen(
                    $registro[$columna],
                    $nombreBase,
                    $fechaCreacion,
                    $identificador,
                    $resultado,
                    $usrId
                );
                
                $resultados[$columna] = $resultadoImg;
                $contador++; // Incrementar contador solo si la imagen se procesó
            } else {
                $resultados[$columna] = [
                    'success' => false,
                    'error' => 'Campo vacío'
                ];
            }
        }
        
        return $resultados;
    }
}
