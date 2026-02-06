<?php

namespace App\Services;

class StringNormalizer
{
    /**
     * Convierte texto a una llave de búsqueda:
     * - minúsculas
     * - sin acentos/diacríticos (á -> a, ñ -> n)
     * - solo letras/números/espacios
     * - espacios colapsados
     */
    public static function toSearchKey(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';

        // Intentar transliteración si existe (ext-intl)
        if (class_exists('\\Transliterator')) {
            try {
                $tr = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC;');
                if ($tr) {
                    $s = $tr->transliterate($s);
                }
            } catch (\Throwable $e) {
                // fallback abajo
            }
        }

        // Fallback: mapping manual (cubre español + algunos comunes)
        $s = strtr($s, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
            'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O', 'Õ' => 'O',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U',
            'ñ' => 'n', 'Ñ' => 'N',
            'ç' => 'c', 'Ç' => 'C',
        ]);

        // Si iconv está disponible, intentar terminar de limpiar otros diacríticos raros
        if (function_exists('iconv')) {
            $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($tmp) && $tmp !== '') {
                $s = $tmp;
            }
        }

        $s = strtolower($s);

        // Reemplazar separadores/raros por espacio y remover lo demás
        $s = preg_replace('/[^a-z0-9]+/i', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        $s = trim($s);
        return $s;
    }

    /**
     * Translitera acentos y diacríticos a ASCII (para uso en nombres de archivo).
     */
    private static function transliterateForFs(string $s): string
    {
        if ($s === '') return '';

        if (class_exists('\\Transliterator')) {
            try {
                $tr = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC;');
                if ($tr) {
                    $s = $tr->transliterate($s);
                }
            } catch (\Throwable $e) {
                // fallback
            }
        }

        $s = strtr($s, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
            'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O', 'Õ' => 'O',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U',
            'ñ' => 'n', 'Ñ' => 'N',
            'ç' => 'c', 'Ç' => 'C',
        ]);

        if (function_exists('iconv')) {
            $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($tmp) && $tmp !== '') {
                $s = $tmp;
            }
        }

        return $s;
    }

    /**
     * Normaliza un segmento de ruta (carpeta o nombre sin extensión).
     * Solo permite [a-zA-Z0-9_.-]; caracteres no válidos se reemplazan por _.
     */
    public static function normalizePathSegment(string $segment): string
    {
        $segment = trim($segment);
        if ($segment === '') return '';

        $s = self::transliterateForFs($segment);
        $s = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', $s);
        $s = preg_replace('/_+/', '_', $s);
        $s = trim($s, '_.-');

        return $s === '' ? '' : $s;
    }

    /**
     * Normaliza un nombre de archivo para el sistema de archivos.
     * Translitera acentos, reemplaza caracteres no válidos por _, mantiene la extensión.
     * Solo permite [a-zA-Z0-9_.-] en nombre y extensión.
     */
    public static function normalizeFilenameForFs(string $name): string
    {
        $name = trim($name);
        if ($name === '') return 'file';

        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        if ($base === '' && $ext !== '') {
            $base = pathinfo($name, PATHINFO_BASENAME);
            $ext = '';
        }

        $base = self::normalizePathSegment($base);
        if ($base === '') {
            $base = 'file';
        }

        if ($ext !== '') {
            $ext = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $ext));
            if ($ext !== '') {
                return $base . '.' . $ext;
            }
        }

        return $base;
    }

    /**
     * Normaliza una ruta relativa completa (carpetas + nombre de archivo).
     * Cada segmento se normaliza; la extensión del último segmento se preserva.
     */
    public static function normalizeRelativePath(string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', $relativePath);
        $relativePath = preg_replace('#/+#', '/', $relativePath);
        $relativePath = trim($relativePath, '/');
        if ($relativePath === '') return '';

        $parts = explode('/', $relativePath);
        $normalized = [];
        foreach ($parts as $i => $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') continue;
            $isLast = ($i === count($parts) - 1);
            $normalized[] = $isLast ? self::normalizeFilenameForFs($part) : self::normalizePathSegment($part);
        }

        return implode('/', $normalized);
    }
}

