<?php

namespace App\Models;

/**
 * Agregado de carpetas (conteo de imágenes) persistido en SQLite (tabla folders)
 * Se regenera a partir de images para mantener consistencia.
 */
class CarpetasIndex
{
    private $pdo;
    private $tFolders;
    private $tImages;

    private function ws(): string
    {
        return \App\Services\AppConnection::currentSlug() ?? 'default';
    }

    private function buildFoldersInsertStmt(): \PDOStatement
    {
        $driver = \App\Services\AppConnection::getCurrentDriver();
        if ($driver === 'mysql') {
            return $this->pdo->prepare("
                INSERT INTO " . $this->tFolders . "(workspace_slug, ruta_carpeta, nombre, search_key, total_imagenes, actualizada_en)
                VALUES(:ws, :ruta, :nombre, :search_key, :total, :t)
                ON DUPLICATE KEY UPDATE
                  nombre = VALUES(nombre),
                  search_key = VALUES(search_key),
                  total_imagenes = VALUES(total_imagenes),
                  actualizada_en = VALUES(actualizada_en)
            ");
        }
        return $this->pdo->prepare("
            INSERT INTO " . $this->tFolders . "(workspace_slug, ruta_carpeta, nombre, search_key, total_imagenes, actualizada_en)
            VALUES(:ws, :ruta, :nombre, :search_key, :total, :t)
            ON CONFLICT(workspace_slug, ruta_carpeta) DO UPDATE SET
              nombre=excluded.nombre,
              search_key=excluded.search_key,
              total_imagenes=excluded.total_imagenes,
              actualizada_en=excluded.actualizada_en
        ");
    }

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
        $this->pdo = \App\Services\AppConnection::get();
        \App\Services\AppSchema::ensure($this->pdo);
        $this->tFolders = \App\Services\AppConnection::table('folders');
        $this->tImages = \App\Services\AppConnection::table('images');
    }

    public function getCarpetas(): array
    {
        $stmt = $this->pdo->prepare('SELECT ruta_carpeta as ruta, nombre, total_imagenes FROM ' . $this->tFolders . ' WHERE workspace_slug = :ws ORDER BY ruta_carpeta ASC');
        $stmt->execute([':ws' => $this->ws()]);
        $rows = $stmt->fetchAll();
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
            FROM " . $this->tFolders . "
            WHERE workspace_slug = :ws AND COALESCE(search_key, '') LIKE :q
            ORDER BY total_imagenes DESC, ruta_carpeta ASC
            LIMIT :lim
        ");
        $stmt->bindValue(':ws', $this->ws(), \PDO::PARAM_STR);
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
        $ws = $this->ws();
        $ahora = date('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $del = $this->pdo->prepare('DELETE FROM ' . $this->tFolders . ' WHERE workspace_slug = :ws');
            $del->execute([':ws' => $ws]);

            $stmtImg = $this->pdo->prepare("
                SELECT COALESCE(ruta_carpeta, '') as ruta, COUNT(*) as total
                FROM " . $this->tImages . "
                WHERE workspace_slug = :ws
                GROUP BY COALESCE(ruta_carpeta, '')
            ");
            $stmtImg->execute([':ws' => $ws]);
            $rows = $stmtImg->fetchAll();

            $stmt = $this->buildFoldersInsertStmt();

            foreach ($rows as $r) {
                $ruta = (string)($r['ruta'] ?? '');
                $total = (int)($r['total'] ?? 0);
                $nombre = ($ruta === '') ? '' : $this->nombreDesdeRuta($ruta);
                $searchKey = \App\Services\StringNormalizer::toSearchKey($ruta . ' ' . $nombre);
                $stmt->execute([
                    ':ws' => $ws,
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

        $cnt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $this->tFolders . ' WHERE workspace_slug = :ws');
        $cnt->execute([':ws' => $ws]);
        $totalCarpetas = (int)$cnt->fetchColumn();
        $sum = $this->pdo->prepare('SELECT COALESCE(SUM(total_imagenes),0) FROM ' . $this->tFolders . ' WHERE workspace_slug = :ws');
        $sum->execute([':ws' => $ws]);
        $totalImagenes = (int)$sum->fetchColumn();

        return [
            'total_carpetas' => $totalCarpetas,
            'total_imagenes' => $totalImagenes
        ];
    }
}

