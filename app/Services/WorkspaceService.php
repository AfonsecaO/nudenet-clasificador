<?php

namespace App\Services;

/**
 * Manejo de workspaces (aislamiento por ambiente).
 *
 * Estructura:
 *   workspaces/<slug>/{db,images,logs,cache}
 */
class WorkspaceService
{
    public const COOKIE_NAME = 'ws';

    /**
     * Raíz absoluta del directorio de workspaces.
     */
    public static function baseDir(): string
    {
        return rtrim(__DIR__ . '/../../workspaces', "/\\");
    }

    /**
     * Normaliza un identificador de workspace (slug).
     * Solo permite [a-z0-9-_] y lo devuelve en minúsculas.
     */
    public static function slugify(string $name): string
    {
        $s = strtolower(trim($name));
        if ($s === '') return '';
        // Reemplazar espacios por guión
        $s = preg_replace('/\s+/', '-', $s);
        // Remover caracteres no permitidos
        $s = preg_replace('/[^a-z0-9\-_]/', '', $s);
        // Colapsar guiones/underscores repetidos
        $s = preg_replace('/[-_]{2,}/', '-', $s);
        $s = trim((string)$s, '-_');
        return $s;
    }

    public static function isValidSlug(string $slug): bool
    {
        return $slug !== '' && preg_match('/^[a-z0-9][a-z0-9\-_]*$/', $slug) === 1;
    }

    /**
     * Lee el workspace actual desde cookie (si existe y es válido).
     */
    public static function current(): ?string
    {
        $raw = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!is_string($raw) || trim($raw) === '') return null;
        $slug = self::slugify($raw);
        if (!self::isValidSlug($slug)) return null;
        if (!self::exists($slug)) return null;
        return $slug;
    }

    /**
     * Setea cookie del workspace actual.
     */
    public static function setCurrent(string $slug): void
    {
        $slug = self::slugify($slug);
        if (!self::isValidSlug($slug)) return;
        // 30 días
        @setcookie(self::COOKIE_NAME, $slug, [
            'expires' => time() + 60 * 60 * 24 * 30,
            'path' => '/',
            'samesite' => 'Lax',
        ]);
        // Para esta request
        $_COOKIE[self::COOKIE_NAME] = $slug;
    }

    /**
     * Limpia la cookie del workspace actual.
     */
    public static function clearCurrent(): void
    {
        @setcookie(self::COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::COOKIE_NAME]);
    }

    public static function root(string $slug): string
    {
        $slug = self::slugify($slug);
        return self::baseDir() . DIRECTORY_SEPARATOR . $slug;
    }

    public static function exists(string $slug): bool
    {
        $slug = self::slugify($slug);
        if (!self::isValidSlug($slug)) return false;
        return is_dir(self::root($slug));
    }

    /**
     * Devuelve paths absolutos del workspace.
     *
     * @return array{dbPath:string,imagesDir:string,logsDir:string,cacheDir:string,thumbsDir:string,avatarsDir:string}
     */
    public static function paths(string $slug): array
    {
        $root = self::root($slug);
        $dbDir = $root . DIRECTORY_SEPARATOR . 'db';
        $imagesDir = $root . DIRECTORY_SEPARATOR . 'images';
        $logsDir = $root . DIRECTORY_SEPARATOR . 'logs';
        $cacheDir = $root . DIRECTORY_SEPARATOR . 'cache';
        $thumbsDir = $cacheDir . DIRECTORY_SEPARATOR . 'thumbs';
        $avatarsDir = $cacheDir . DIRECTORY_SEPARATOR . 'avatars';
        return [
            'dbPath' => $dbDir . DIRECTORY_SEPARATOR . 'clasificador.sqlite',
            'imagesDir' => $imagesDir,
            'logsDir' => $logsDir,
            'cacheDir' => $cacheDir,
            'thumbsDir' => $thumbsDir,
            'avatarsDir' => $avatarsDir,
        ];
    }

    /**
     * Lista los workspaces existentes (slugs).
     *
     * @return string[]
     */
    public static function listWorkspaces(): array
    {
        $base = self::baseDir();
        if (!is_dir($base)) return [];
        $items = @scandir($base) ?: [];
        $out = [];
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') continue;
            $slug = self::slugify((string)$it);
            if (!self::isValidSlug($slug)) continue;
            if (is_dir($base . DIRECTORY_SEPARATOR . $slug)) $out[] = $slug;
        }
        sort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /**
     * Crea (si hace falta) la estructura base del workspace.
     */
    public static function ensureStructure(string $slug): bool
    {
        $slug = self::slugify($slug);
        if (!self::isValidSlug($slug)) return false;

        $base = self::baseDir();
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        if (!is_dir($base)) return false;

        $p = self::paths($slug);
        foreach ([$p['imagesDir'], dirname($p['dbPath']), $p['logsDir'], $p['cacheDir'], $p['thumbsDir'], $p['avatarsDir']] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (!is_dir($dir)) return false;
        }
        return true;
    }

    /**
     * Elimina un workspace completo (NO hay marcha atrás).
     * - Valida slug
     * - Protege contra path traversal (realpath dentro de baseDir)
     */
    public static function deleteWorkspace(string $slug): bool
    {
        $slug = self::slugify($slug);
        if (!self::isValidSlug($slug)) return false;

        $base = self::baseDir();
        $root = self::root($slug);

        if (!is_dir($root)) return false;

        $baseReal = realpath($base);
        $rootReal = realpath($root);
        if ($baseReal === false || $rootReal === false) return false;

        // Asegurar que rootReal está dentro de baseReal
        $baseReal = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $baseReal), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $rootReal = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rootReal), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strpos($rootReal, $baseReal) !== 0) return false;

        return self::rrmdir($rootReal);
    }

    private static function rrmdir(string $dir): bool
    {
        if (!is_dir($dir)) return false;
        $items = @scandir($dir);
        if ($items === false) return false;
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') continue;
            $p = $dir . DIRECTORY_SEPARATOR . $it;
            if (is_dir($p)) {
                if (!self::rrmdir($p)) return false;
            } else {
                if (!@unlink($p)) return false;
            }
        }
        return @rmdir($dir);
    }
}

