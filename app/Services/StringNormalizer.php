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
}

