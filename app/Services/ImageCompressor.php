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
        $quality = self::DEFAULT_JPEG_QUALITY;
        try {
            $q = ConfigService::obtenerOpcional('IMAGE_JPEG_QUALITY', (string) $quality);
            if ($q !== null && $q !== '') {
                $quality = max(1, min(100, (int) $q));
            }
        } catch (\Throwable $e) {
            // usar default
        }

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
        $level = self::DEFAULT_PNG_COMPRESSION_LEVEL; // 0-9, 9 máximo
        try {
            $l = ConfigService::obtenerOpcional('IMAGE_PNG_COMPRESSION', (string) $level);
            if ($l !== null && $l !== '') {
                $level = max(0, min(9, (int) $l));
            }
        } catch (\Throwable $e) {
            // usar default
        }

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
}
