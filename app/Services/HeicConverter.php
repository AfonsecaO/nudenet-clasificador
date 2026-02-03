<?php

namespace App\Services;

/**
 * Convierte imágenes HEIC/HEIF a JPG para compatibilidad.
 * Usa Imagick (si tiene soporte HEIC) o ImageMagick/heif-convert por línea de comandos.
 */
class HeicConverter
{
    private static ?bool $imagickAvailable = null;

    public static function isHeicExtension(string $pathOrExt): bool
    {
        $ext = pathinfo($pathOrExt, PATHINFO_EXTENSION);
        if ($ext !== '') {
            $ext = strtolower($ext);
            return ($ext === 'heic' || $ext === 'heif');
        }
        return false;
    }

    public static function isHeicMime(?string $mime): bool
    {
        if ($mime === null || $mime === '') return false;
        $m = strtolower($mime);
        return (strpos($m, 'image/heic') !== false || strpos($m, 'image/heif') !== false);
    }

    /**
     * Convierte un archivo HEIC/HEIF a JPG. Crea un archivo .jpg en el mismo directorio.
     * @return string|null Ruta del archivo JPG creado, o null si falla o no es HEIC.
     */
    public static function convertFileToJpg(string $heicPath): ?string
    {
        if (!is_file($heicPath)) return null;
        $ext = strtolower(pathinfo($heicPath, PATHINFO_EXTENSION));
        if ($ext !== 'heic' && $ext !== 'heif') return null;

        $dir = dirname($heicPath);
        $base = pathinfo($heicPath, PATHINFO_FILENAME);
        $jpgPath = $dir . DIRECTORY_SEPARATOR . $base . '.jpg';

        // 1) Imagick
        $bin = self::convertFileToJpgBinary($heicPath);
        if ($bin !== null) {
            if (@file_put_contents($jpgPath, $bin) !== false) {
                return $jpgPath;
            }
            return null;
        }

        // 2) ImageMagick convert/magick
        $cmd = self::buildImageMagickCommand($heicPath, $jpgPath);
        if ($cmd !== null && self::execConvert($cmd)) {
            return is_file($jpgPath) ? $jpgPath : null;
        }

        // 3) heif-convert (libheif)
        $cmdHeif = self::buildHeifConvertCommand($heicPath, $jpgPath);
        if ($cmdHeif !== null && self::execConvert($cmdHeif)) {
            return is_file($jpgPath) ? $jpgPath : null;
        }

        return null;
    }

    /**
     * Convierte datos binarios HEIC/HEIF a binario JPG.
     * @return string|null Contenido JPG o null si falla.
     */
    public static function convertBinaryToJpg(string $heicBinary): ?string
    {
        if (strlen($heicBinary) < 12) return null;

        if (self::imagickAvailable()) {
            try {
                $im = new \Imagick();
                $im->readImageBlob($heicBinary);
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality(92);
                $blob = $im->getImageBlob();
                $im->clear();
                $im->destroy();
                return $blob !== false ? $blob : null;
            } catch (\Throwable $e) {
                // Imagick sin soporte HEIC o error
            }
        }

        $tmp = @tempnam(sys_get_temp_dir(), 'heic');
        if ($tmp === false) return null;
        $tmpJpg = $tmp . '.jpg';
        $ok = @file_put_contents($tmp, $heicBinary) !== false;
        if (!$ok) {
            @unlink($tmp);
            return null;
        }
        $outPath = $tmp . '_out.jpg';
        $cmd = self::buildImageMagickCommand($tmp, $outPath);
        if ($cmd !== null && self::execConvert($cmd) && is_file($outPath)) {
            $jpg = @file_get_contents($outPath);
            @unlink($tmp);
            @unlink($outPath);
            return $jpg ?: null;
        }
        $cmdHeif = self::buildHeifConvertCommand($tmp, $outPath);
        if ($cmdHeif !== null && self::execConvert($cmdHeif) && is_file($outPath)) {
            $jpg = @file_get_contents($outPath);
            @unlink($tmp);
            @unlink($outPath);
            return $jpg ?: null;
        }
        @unlink($tmp);
        if (is_file($outPath)) @unlink($outPath);
        return null;
    }

    /**
     * Convierte archivo HEIC a binario JPG (para no escribir en disco hasta decidir ruta final).
     */
    public static function convertFileToJpgBinary(string $heicPath): ?string
    {
        if (!is_file($heicPath)) return null;

        if (self::imagickAvailable()) {
            try {
                $im = new \Imagick($heicPath);
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality(92);
                $blob = $im->getImageBlob();
                $im->clear();
                $im->destroy();
                return $blob !== false ? $blob : null;
            } catch (\Throwable $e) {
                // no soporte HEIC
            }
        }

        $data = @file_get_contents($heicPath);
        return $data !== false ? self::convertBinaryToJpg($data) : null;
    }

    private static function imagickAvailable(): bool
    {
        if (self::$imagickAvailable === null) {
            self::$imagickAvailable = extension_loaded('imagick') && class_exists(\Imagick::class);
        }
        return self::$imagickAvailable;
    }

    private static function buildImageMagickCommand(string $input, string $output): ?string
    {
        $inputEsc = self::escapePath($input);
        $outputEsc = self::escapePath($output);
        $silent = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? ' 2>NUL' : ' 2>/dev/null';
        if (self::commandExists('magick')) {
            return 'magick ' . $inputEsc . ' ' . $outputEsc . $silent;
        }
        if (self::commandExists('convert')) {
            return 'convert ' . $inputEsc . ' ' . $outputEsc . $silent;
        }
        return null;
    }

    private static function buildHeifConvertCommand(string $input, string $output): ?string
    {
        if (!self::commandExists('heif-convert')) return null;
        $inputEsc = self::escapePath($input);
        $outputEsc = self::escapePath($output);
        $silent = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? ' 2>NUL' : ' 2>/dev/null';
        return 'heif-convert ' . $inputEsc . ' ' . $outputEsc . $silent;
    }

    private static function escapePath(string $path): string
    {
        return '"' . str_replace(['"', '%'], ['\"', '%%'], $path) . '"';
    }

    private static function commandExists(string $cmd): bool
    {
        $wrapper = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
            ? 'where %s 2>NUL'
            : 'command -v %s 2>/dev/null';
        @exec(sprintf($wrapper, $cmd), $out, $ret);
        return $ret === 0 && !empty($out);
    }

    private static function execConvert(string $command): bool
    {
        @exec($command, $out, $ret);
        return $ret === 0;
    }
}
