<?php

namespace App\Models;

class EstadoTracker
{
    private $pdo;

    public function __construct()
    {
        \App\Services\ConfigService::cargarYValidar();
        $this->pdo = \App\Services\SqliteConnection::get();
        \App\Services\SqliteSchema::ensure($this->pdo);
    }

    /**
     * Obtiene el último ID procesado para una tabla
     */
    public function getUltimoId($tabla)
    {
        $stmt = $this->pdo->prepare('SELECT ultimo_id FROM tables_state WHERE tabla = :t');
        $stmt->execute([':t' => $tabla]);
        $v = $stmt->fetchColumn();
        return $v !== false ? (int)$v : 0;
    }

    /**
     * Actualiza el último ID procesado para una tabla
     */
    public function actualizarUltimoId($tabla, $id, $faltanRegistros = true)
    {
        $this->inicializarTabla($tabla);
        $now = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare('SELECT max_id FROM tables_state WHERE tabla = :t');
        $stmt->execute([':t' => $tabla]);
        $maxId = $stmt->fetchColumn();
        $maxId = ($maxId !== false) ? (int)$maxId : null;

        $faltan = ($maxId !== null) ? (((int)$id) < $maxId) : (($faltanRegistros !== false) ? 1 : 0);

        $upd = $this->pdo->prepare("
            UPDATE tables_state
            SET ultimo_id = :u, faltan_registros = :f, ultima_actualizacion = :ua
            WHERE tabla = :t
        ");
        $upd->execute([
            ':u' => (int)$id,
            ':f' => $faltan ? 1 : 0,
            ':ua' => $now,
            ':t' => $tabla
        ]);
    }
    
    /**
     * Actualiza el ID máximo de una tabla (preserva el último ID procesado)
     */
    public function actualizarMaxId($tabla, $maxId)
    {
        $this->inicializarTabla($tabla);
        $now = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare('SELECT ultimo_id FROM tables_state WHERE tabla = :t');
        $stmt->execute([':t' => $tabla]);
        $ultimo = $stmt->fetchColumn();
        $ultimo = ($ultimo !== false) ? (int)$ultimo : 0;

        $faltan = ($ultimo < (int)$maxId) ? 1 : 0;

        $upd = $this->pdo->prepare("
            UPDATE tables_state
            SET max_id = :m, faltan_registros = :f, ultima_actualizacion_contador = :uac
            WHERE tabla = :t
        ");
        $upd->execute([
            ':m' => (int)$maxId,
            ':f' => $faltan,
            ':uac' => $now,
            ':t' => $tabla
        ]);

        // Mantener tables_index sincronizado si existe snapshot
        $up2 = $this->pdo->prepare("
            INSERT INTO tables_index(tabla, max_id) VALUES(:t, :m)
            ON CONFLICT(tabla) DO UPDATE SET max_id=excluded.max_id
        ");
        $up2->execute([':t' => $tabla, ':m' => (int)$maxId]);
    }

    /**
     * Verifica si faltan registros para una tabla
     */
    public function faltanRegistros($tabla)
    {
        $stmt = $this->pdo->prepare('SELECT faltan_registros FROM tables_state WHERE tabla = :t');
        $stmt->execute([':t' => $tabla]);
        $v = $stmt->fetchColumn();
        if ($v === false) return true;
        return ((int)$v) !== 0;
    }

    /**
     * Obtiene todo el estado
     */
    public function getEstado()
    {
        $rows = $this->pdo->query('SELECT * FROM tables_state')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $tabla = (string)($r['tabla'] ?? '');
            if ($tabla === '') continue;
            $out[$tabla] = [
                'ultimo_id' => (int)($r['ultimo_id'] ?? 0),
                'max_id' => (int)($r['max_id'] ?? 0),
                'faltan_registros' => ((int)($r['faltan_registros'] ?? 1)) !== 0,
                'ultima_actualizacion' => $r['ultima_actualizacion'] ?? null,
                'ultima_actualizacion_contador' => $r['ultima_actualizacion_contador'] ?? null
            ];
        }
        return $out;
    }

    /**
     * Obtiene el índice persistido de tablas (snapshot)
     */
    public function getIndiceTablas()
    {
        $pattern = \App\Services\SqliteSchema::metaGet($this->pdo, 'tables_pattern', null);
        $updatedAt = \App\Services\SqliteSchema::metaGet($this->pdo, 'tables_index_updated_at', null);
        $rows = $this->pdo->query('SELECT tabla, max_id FROM tables_index ORDER BY max_id ASC')->fetchAll();
        $tablas = [];
        foreach ($rows as $r) {
            $tablas[] = [
                'tabla' => (string)$r['tabla'],
                'max_id' => (int)$r['max_id']
            ];
        }
        return [
            'pattern' => $pattern,
            'actualizada_en' => $updatedAt,
            'tablas' => $tablas
        ];
    }

    /**
     * Actualiza el índice persistido de tablas (snapshot)
     * @param array $tablas Array de elementos tipo: ['tabla' => string, 'max_id' => int]
     * @param string|null $pattern
     */
    public function setIndiceTablas(array $tablas, $pattern = null)
    {
        $now = date('Y-m-d H:i:s');
        \App\Services\SqliteSchema::metaSet($this->pdo, 'tables_pattern', $pattern ?? '');
        \App\Services\SqliteSchema::metaSet($this->pdo, 'tables_index_updated_at', $now);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM tables_index');
            $stmt = $this->pdo->prepare('INSERT INTO tables_index(tabla, max_id) VALUES(:t, :m)');
            foreach ($tablas as $t) {
                if (!is_array($t)) continue;
                $tabla = (string)($t['tabla'] ?? '');
                if ($tabla === '') continue;
                $stmt->execute([':t' => $tabla, ':m' => (int)($t['max_id'] ?? 0)]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
    
    /**
     * Recarga el estado desde el archivo
     */
    public function recargarEstado()
    {
        // SQLite: siempre se lee del storage; no hay cache local.
    }

    /**
     * Inicializa el estado para una tabla si no existe
     */
    public function inicializarTabla($tabla)
    {
        $stmt = $this->pdo->prepare('INSERT INTO tables_state(tabla) VALUES(:t) ON CONFLICT(tabla) DO NOTHING');
        $stmt->execute([':t' => $tabla]);
    }

    /**
     * Sincroniza las tablas: agrega nuevas y elimina las que ya no existen
     */
    public function sincronizarTablas($tablasExistentes)
    {
        $tablasExistentes = array_values(array_filter($tablasExistentes, fn($t) => is_string($t) && $t !== ''));
        $set = array_fill_keys($tablasExistentes, true);

        $antes = $this->pdo->query('SELECT tabla FROM tables_state')->fetchAll();
        $antesSet = [];
        foreach ($antes as $r) $antesSet[(string)$r['tabla']] = true;

        $cambios = false;
        foreach ($tablasExistentes as $t) {
            if (!isset($antesSet[$t])) {
                $this->inicializarTabla($t);
                $cambios = true;
            }
        }

        foreach (array_keys($antesSet) as $t) {
            if (!isset($set[$t])) {
                $del = $this->pdo->prepare('DELETE FROM tables_state WHERE tabla = :t');
                $del->execute([':t' => $t]);
                $del2 = $this->pdo->prepare('DELETE FROM tables_index WHERE tabla = :t');
                $del2->execute([':t' => $t]);
                $cambios = true;
            }
        }

        return $cambios;
    }

    /**
     * Verifica si una tabla existe en el estado
     */
    public function tablaExiste($tabla)
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM tables_state WHERE tabla = :t LIMIT 1');
        $stmt->execute([':t' => $tabla]);
        return $stmt->fetchColumn() !== false;
    }
}
