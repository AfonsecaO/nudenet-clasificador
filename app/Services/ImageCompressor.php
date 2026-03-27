<?php

namespace App\Services;

/**
 * Comprime imágenes JPEG/PNG manteniendo extensión y calidad aceptable.
 * Usa GD o Imagick para re-codificar y reducir peso.
 */
class ImageCompressor
{
    private const DEFAULT_JPEG_QUALITY = 85;
    private const DEFAULT_PNG_COMPRESSION_LEVEL = 8;

    /** @var int|null Cache de calidad JPEG por request */
    private static ?int $cachedJpegQuality = null;
    /** @var int|null Cache de nivel PNG por request */
    private static ?int $cachedPngLevel = null;

    /**
     * Comprime el binario de una imagen según su extensión.
     *
     * @param string $binary   Contenido binario de la imagen
     * @param string $extension Extensión: jpg, jpeg o png
     * @return string|null     Binario comprimido, o null si no se pudo comprimir (usar original)
     */
    public static function compress(string $binary, string $extension): ?string
    {
        $extension = strtolower(trim($extension));
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }
        if (!in_array($extension, ['jpg', 'png'], true)) {
            return null;
        }

        $result = null;
        if ($extension === 'jpg') {
            $result = self::compressJpeg($binary);
        } elseif ($extension === 'png') {
            $result = self::compressPng($binary);
        }

        if ($result === null || $result === '') {
            return null;
        }

        // Solo devolver comprimido si es más pequeño que el original
        if (strlen($result) >= strlen($binary)) {
            return null;
        }

        return $result;
    }

    /**
     * Comprime JPEG con GD o Imagick.
     */
    private static function compressJpeg(string $binary): ?string
    {
        if (self::$cachedJpegQuality === null) {
            self::$cachedJpegQuality = self::DEFAULT_JPEG_QUALITY;
            try {
                $q = ConfigService::obtenerOpcional('IMAGE_JPEG_QUALITY', (string) self::DEFAULT_JPEG_QUALITY);
                if ($q !== null && $q !== '') {
                    self::$cachedJpegQuality = max(1, min(100, (int) $q));
                }
            } catch (\Throwable $e) {
                // usar default
            }
        }
        $quality = self::$cachedJpegQuality;

        if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
            $img = @imagecreatefromstring($binary);
            if ($img === false) {
                return null;
            }
            ob_start();
            $ok = @imagejpeg($img, null, $quality);
            imagedestroy($img);
            if (!$ok) {
                ob_end_clean();
                return null;
            }
            $out = ob_get_clean();
            return $out !== false ? $out : null;
        }

        if (extension_loaded('imagick')) {
            $imagickClass = 'Imagick';
            if (class_exists($imagickClass)) {
                try {
                    $im = new $imagickClass();
                    $im->readImageBlob($binary);
                    $im->setImageFormat('jpeg');
                    $im->setImageCompressionQuality($quality);
                    $im->stripImage();
                    $blob = $im->getImageBlob();
                    $im->clear();
                    $im->destroy();
                    return $blob !== '' ? $blob : null;
                } catch (\Throwable $e) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * Comprime PNG con GD o Imagick.
     */
    private static function compressPng(string $binary): ?string
    {
        if (self::$cachedPngLevel === null) {
            self::$cachedPngLevel = self::DEFAULT_PNG_COMPRESSION_LEVEL;
            try {
                $l = ConfigService::obtenerOpcional('IMAGE_PNG_COMPRESSION', (string) self::DEFAULT_PNG_COMPRESSION_LEVEL);
                if ($l !== null && $l !== '') {
                    self::$cachedPngLevel = max(0, min(9, (int) $l));
                }
            } catch (\Throwable $e) {
                // usar default
            }
        }
        $level = self::$cachedPngLevel;

        if (function_exists('imagecreatefromstring') && function_exists('imagepng')) {
            $img = @imagecreatefromstring($binary);
            if ($img === false) {
                return null;
            }
            ob_start();
            $ok = @imagepng($img, null, $level);
            imagedestroy($img);
            if (!$ok) {
                ob_end_clean();
                return null;
            }
            $out = ob_get_clean();
            return $out !== false ? $out : null;
        }

        if (extension_loaded('imagick')) {
            $imagickClass = 'Imagick';
            if (class_exists($imagickClass)) {
                try {
                    $im = new $imagickClass();
                    $im->readImageBlob($binary);
                    $im->setImageFormat('png');
                    $im->stripImage();
                    $im->setImageCompressionQuality(85); // 0-100 para PNG en Imagick
                    $blob = $im->getImageBlob();
                    $im->clear();
                    $im->destroy();
                    return $blob !== '' ? $blob : null;
                } catch (\Throwable $e) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * Reduce la imagen a un tamaño en bytes ≤ $maxBytes (reescala y/o re-codifica a JPEG).
     * Útil para cumplir límites de APIs (p. ej. Rekognition 5 MB).
     *
     * @param string $binary   Contenido binario de la imagen (JPEG/PNG/WebP/etc.)
     * @param int    $maxBytes Límite máximo en bytes (ej. 5242880 para Rekognition)
     * @return string|null     Binario JPEG con strlen ≤ $maxBytes, o null si no se pudo procesar
     */
    public static function shrinkToMaxBytes(string $binary, int $maxBytes): ?string
    {
        if ($maxBytes <= 0) {
            return null;
        }
        if (strlen($binary) <= $maxBytes) {
            return $binary;
        }

        $img = null;
        $useImagick = false;

        if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
            $img = @imagecreatefromstring($binary);
        }
        if ($img === false && extension_loaded('imagick') && class_exists(\Imagick::class)) {
            $useImagick = true;
        }
        if ($img === false && !$useImagick) {
            return null;
        }

        $maxIterations = 20;
        $quality = 85;
        $scale = 1.0;
        $minQuality = 50;

        for ($iter = 0; $iter < $maxIterations; $iter++) {
            if ($useImagick) {
                $out = self::shrinkToMaxBytesImagick($binary, $scale, $quality);
            } else {
                $out = self::shrinkToMaxBytesGd($img, $scale, $quality);
            }
            if ($out !== null && strlen($out) <= $maxBytes) {
                if ($img !== null) {
                    @imagedestroy($img);
                }
                return $out;
            }
            if ($out !== null && strlen($out) > $maxBytes) {
                $scale *= 0.8;
                if ($scale < 0.2) {
                    $scale = 0.2;
                    $quality = max($minQuality, $quality - 10);
                }
            }
        }

        if ($img !== null) {
            @imagedestroy($img);
        }
        return null;
    }

    private static function shrinkToMaxBytesGd($img, float $scale, int $quality): ?string
    {
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w <= 0 || $h <= 0) {
            return null;
        }
        $nw = (int) max(1, round($w * $scale));
        $nh = (int) max(1, round($h * $scale));
        $dst = @imagecreatetruecolor($nw, $nh);
        if ($dst === false) {
            return null;
        }
        $ok = @imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        if (!$ok) {
            imagedestroy($dst);
            return null;
        }
        ob_start();
        @imagejpeg($dst, null, $quality);
        imagedestroy($dst);
        $out = ob_get_clean();
        return $out !== false ? $out : null;
    }

    private static function shrinkToMaxBytesImagick(string $binary, float $scale, int $quality): ?string
    {
        try {
            $im = new \Imagick();
            $im->readImageBlob($binary);
            $w = $im->getImageWidth();
            $h = $im->getImageHeight();
            if ($w <= 0 || $h <= 0) {
                $im->clear();
                $im->destroy();
                return null;
            }
            $nw = (int) max(1, round($w * $scale));
            $nh = (int) max(1, round($h * $scale));
            $im->resizeImage($nw, $nh, \Imagick::FILTER_LANCZOS, 1);
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality($quality);
            $im->stripImage();
            $blob = $im->getImageBlob();
            $im->clear();
            $im->destroy();
            return $blob !== '' ? $blob : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Resetea el cache de configuración (útil en tests).
     */
    public static function resetConfigCache(): void
    {
        self::$cachedJpegQuality = null;
        self::$cachedPngLevel = null;
    }
}
