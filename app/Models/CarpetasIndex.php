<?php

namespace App\Models;

/**
 * Agregado de carpetas (conteo de imágenes) persistido en SQLite (tabla folders)
 * Se regenera a partir de images para mantener consistencia.
 */
class CarpetasIndex
{
    private $pdo;

    private function nombreDesdeRuta(string $ruta): string
    {
        $ruta = str_replace('\\', '/', $ruta);
        $ruta = trim($ruta, '/');
        if ($ruta === '') return '';
        return basename($ruta);
    }

    public function __construct()
    {
        \App\Services\ConfigService::cargarYValidar();
        $this->pdo = \App\Services\SqliteConnection::get();
        \App\Services\SqliteSchema::ensure($this->pdo);
    }

    public function getCarpetas(): array
    {
        $rows = $this->pdo->query('SELECT ruta_carpeta as ruta, nombre, total_imagenes FROM folders ORDER BY ruta_carpeta ASC')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $ruta = (string)($r['ruta'] ?? '');
            $nombre = (string)($r['nombre'] ?? '');
            if ($nombre === '' && $ruta !== '') {
                $nombre = $this->nombreDesdeRuta($ruta);
            }
            $out[] = [
                'ruta' => $ruta,
                'nombre' => $nombre,
                'total_imagenes' => (int)($r['total_imagenes'] ?? 0)
            ];
        }
        return $out;
    }

    /**
     * Búsqueda por subcadena sobre llave normalizada (search_key).
     */
    public function buscarPorSearchKey(string $searchKey, int $limit = 200): array
    {
        $searchKey = trim($searchKey);
        if ($searchKey === '') return [];
        $limit = max(1, min(500, (int)$limit));

        $stmt = $this->pdo->prepare("
            SELECT ruta_carpeta as ruta, nombre, total_imagenes
            FROM folders
            WHERE COALESCE(search_key, '') LIKE :q
            ORDER BY total_imagenes DESC, ruta_carpeta ASC
            LIMIT :lim
        ");
        $stmt->bindValue(':q', '%' . $searchKey . '%', \PDO::PARAM_STR);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];

        $out = [];
        foreach ($rows as $r) {
            $ruta = (string)($r['ruta'] ?? '');
            $nombre = (string)($r['nombre'] ?? '');
            if ($nombre === '' && $ruta !== '') {
                $nombre = $this->nombreDesdeRuta($ruta);
            }
            $out[] = [
                'ruta' => $ruta,
                'nombre' => $nombre,
                'total_imagenes' => (int)($r['total_imagenes'] ?? 0)
            ];
        }
        return $out;
    }

    /**
     * Regenera el índice de carpetas a partir del índice de imágenes.
     */
    public function regenerarDesdeImagenes(ImagenesIndex $imagenesIndex): array
    {
        $ahora = date('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM folders');

            $rows = $this->pdo->query("
                SELECT COALESCE(ruta_carpeta, '') as ruta, COUNT(*) as total
                FROM images
                GROUP BY COALESCE(ruta_carpeta, '')
            ")->fetchAll();

            $stmt = $this->pdo->prepare("
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
                $nombre = ($ruta === '') ? '' : $this->nombreDesdeRuta($ruta);
                $searchKey = \App\Services\StringNormalizer::toSearchKey($ruta . ' ' . $nombre);
                $stmt->execute([
                    ':ruta' => $ruta,
                    ':nombre' => $nombre,
                    ':search_key' => $searchKey,
                    ':total' => $total,
                    ':t' => $ahora
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $totalCarpetas = (int)$this->pdo->query('SELECT COUNT(*) FROM folders')->fetchColumn();
        $totalImagenes = (int)$this->pdo->query('SELECT SUM(total_imagenes) FROM folders')->fetchColumn();

        return [
            'total_carpetas' => $totalCarpetas,
            'total_imagenes' => $totalImagenes
        ];
    }
}

