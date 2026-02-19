<?php

namespace App\Models;

class EstadoTracker
{
    private $pdo;

    private $tTablesState;
    private $tTablesIndex;

    private function ws(): string
    {
        return \App\Services\AppConnection::currentSlug() ?? 'default';
    }

    public function __construct()
    {
        \App\Services\ConfigService::cargarYValidar();
        $this->pdo = \App\Services\AppConnection::get();
        \App\Services\AppSchema::ensure($this->pdo);
        $this->tTablesState = \App\Services\AppConnection::table('tables_state');
        $this->tTablesIndex = \App\Services\AppConnection::table('tables_index');
    }

    /**
     * Obtiene el último ID procesado para una tabla
     */
    public function getUltimoId($tabla)
    {
        $stmt = $this->pdo->prepare('SELECT ultimo_id FROM ' . $this->tTablesState . ' WHERE workspace_slug = :ws AND tabla = :t');
        $stmt->execute([':ws' => $this->ws(), ':t' => $tabla]);
        $v = $stmt->fetchColumn();
        return $v !== false ? (int)$v : 0;
    }

    /**
     * Actualiza el último ID (siguiente id a intentar) para una tabla.
     * Si $faltanRegistros === null: solo actualiza ultimo_id y ultima_actualizacion (no consulta max_id).
     */
    public function actualizarUltimoId($tabla, $id, $faltanRegistros = true)
    {
        $this->inicializarTabla($tabla);
        $now = date('Y-m-d H:i:s');
        $ws = $this->ws();

        if ($faltanRegistros === null) {
            $upd = $this->pdo->prepare("
                UPDATE " . $this->tTablesState . "
                SET ultimo_id = :u, ultima_actualizacion = :ua
                WHERE workspace_slug = :ws AND tabla = :t
            ");
            $upd->execute([
                ':u' => (int)$id,
                ':ua' => $now,
                ':ws' => $ws,
                ':t' => $tabla
            ]);
            return;
        }

        $stmt = $this->pdo->prepare('SELECT max_id FROM ' . $this->tTablesState . ' WHERE workspace_slug = :ws AND tabla = :t');
        $stmt->execute([':ws' => $ws, ':t' => $tabla]);
        $maxId = $stmt->fetchColumn();
        $maxId = ($maxId !== false) ? (int)$maxId : null;
        if ($faltanRegistros === false) {
            $faltan = 0;
        } else {
            $faltan = ($maxId !== null) ? (((int)$id) < $maxId) : 1;
        }

        $upd = $this->pdo->prepare("
            UPDATE " . $this->tTablesState . "
            SET ultimo_id = :u, faltan_registros = :f, ultima_actualizacion = :ua
            WHERE workspace_slug = :ws AND tabla = :t
        ");
        $upd->execute([
            ':u' => (int)$id,
            ':f' => $faltan ? 1 : 0,
            ':ua' => $now,
            ':ws' => $ws,
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
        $ws = $this->ws();

        $stmt = $this->pdo->prepare('SELECT ultimo_id FROM ' . $this->tTablesState . ' WHERE workspace_slug = :ws AND tabla = :t');
        $stmt->execute([':ws' => $ws, ':t' => $tabla]);
        $ultimo = $stmt->fetchColumn();
        $ultimo = ($ultimo !== false) ? (int)$ultimo : 0;
        // ultimo_id = último ID procesado; faltan si quedan ids por procesar (ultimo < max_id)
        $faltan = ($ultimo < (int)$maxId) ? 1 : 0;

        $upd = $this->pdo->prepare("
            UPDATE " . $this->tTablesState . "
            SET max_id = :m, faltan_registros = :f, ultima_actualizacion_contador = :uac
            WHERE workspace_slug = :ws AND tabla = :t
        ");
        $upd->execute([
            ':m' => (int)$maxId,
            ':f' => $faltan,
            ':uac' => $now,
            ':ws' => $ws,
            ':t' => $tabla
        ]);

        $driver = \App\Services\AppConnection::getCurrentDriver();
        if ($driver === 'mysql') {
            $up2 = $this->pdo->prepare("
                INSERT INTO " . $this->tTablesIndex . "(workspace_slug, tabla, max_id) VALUES(:ws, :t, :m)
                ON DUPLICATE KEY UPDATE max_id = VALUES(max_id)
            ");
        } else {
            $up2 = $this->pdo->prepare("
                INSERT INTO " . $this->tTablesIndex . "(workspace_slug, tabla, max_id) VALUES(:ws, :t, :m)
                ON CONFLICT(workspace_slug, tabla) DO UPDATE SET max_id=excluded.max_id
            ");
        }
        $up2->execute([':ws' => $ws, ':t' => $tabla, ':m' => (int)$maxId]);
    }

    /**
     * Verifica si faltan registros para una tabla
     */
    public function faltanRegistros($tabla)
    {
        $stmt = $this->pdo->prepare('SELECT faltan_registros FROM ' . $this->tTablesState . ' WHERE workspace_slug = :ws AND tabla = :t');
        $stmt->execute([':ws' => $this->ws(), ':t' => $tabla]);
        $v = $stmt->fetchColumn();
        if ($v === false) return true;
        return ((int)$v) !== 0;
    }

    /**
     * Obtiene todo el estado
     */
    public function getEstado()
    {
        $stmt = $this->pdo->prepare('SELECT tabla, ultimo_id, max_id, faltan_registros, ultima_actualizacion, ultima_actualizacion_contador FROM ' . $this->tTablesState . ' WHERE workspace_slug = :ws');
        $stmt->execute([':ws' => $this->ws()]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
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
        $ws = $this->ws();
        $pattern = \App\Services\AppSchema::metaGet($this->pdo, 'tables_pattern', null, $ws);
        $updatedAt = \App\Services\AppSchema::metaGet($this->pdo, 'tables_index_updated_at', null, $ws);
        $stmt = $this->pdo->prepare('SELECT tabla, max_id FROM ' . $this->tTablesIndex . ' WHERE workspace_slug = :ws ORDER BY max_id ASC');
        $stmt->execute([':ws' => $ws]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
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
        $ws = $this->ws();
        \App\Services\AppSchema::metaSet($this->pdo, 'tables_pattern', $pattern ?? '', $ws);
        \App\Services\AppSchema::metaSet($this->pdo, 'tables_index_updated_at', $now, $ws);

        $this->pdo->beginTransaction();
        try {
            $del = $this->pdo->prepare('DELETE FROM ' . $this->tTablesIndex . ' WHERE workspace_slug = :ws');
            $del->execute([':ws' => $ws]);
            $stmt = $this->pdo->prepare('INSERT INTO ' . $this->tTablesIndex . '(workspace_slug, tabla, max_id) VALUES(:ws, :t, :m)');
            foreach ($tablas as $t) {
                if (!is_array($t)) continue;
                $tabla = (string)($t['tabla'] ?? '');
                if ($tabla === '') continue;
                $stmt->execute([':ws' => $ws, ':t' => $tabla, ':m' => (int)($t['max_id'] ?? 0)]);
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
        $ws = $this->ws();
        $driver = \App\Services\AppConnection::getCurrentDriver();
        if ($driver === 'mysql') {
            $stmt = $this->pdo->prepare('INSERT IGNORE INTO ' . $this->tTablesState . '(workspace_slug, tabla) VALUES(:ws, :t)');
        } else {
            $stmt = $this->pdo->prepare('INSERT INTO ' . $this->tTablesState . '(workspace_slug, tabla) VALUES(:ws, :t) ON CONFLICT(workspace_slug, tabla) DO NOTHING');
        }
        $stmt->execute([':ws' => $ws, ':t' => $tabla]);
    }

    /**
     * Sincroniza las tablas: agrega nuevas y elimina las que ya no existen
     */
    public function sincronizarTablas($tablasExistentes)
    {
        $tablasExistentes = array_values(array_filter($tablasExistentes, fn($t) => is_string($t) && $t !== ''));
        $set = array_fill_keys($tablasExistentes, true);
        $ws = $this->ws();

        $stmt = $this->pdo->prepare('SELECT tabla FROM ' . $this->tTablesState . ' WHERE workspace_slug = :ws');
        $stmt->execute([':ws' => $ws]);
        $antes = $stmt->fetchAll(\PDO::FETCH_ASSOC);
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
                $del = $this->pdo->prepare('DELETE FROM ' . $this->tTablesState . ' WHERE workspace_slug = :ws AND tabla = :t');
                $del->execute([':ws' => $ws, ':t' => $t]);
                $del2 = $this->pdo->prepare('DELETE FROM ' . $this->tTablesIndex . ' WHERE workspace_slug = :ws AND tabla = :t');
                $del2->execute([':ws' => $ws, ':t' => $t]);
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
        $stmt = $this->pdo->prepare('SELECT 1 FROM ' . $this->tTablesState . ' WHERE workspace_slug = :ws AND tabla = :t LIMIT 1');
        $stmt->execute([':ws' => $this->ws(), ':t' => $tabla]);
        return $stmt->fetchColumn() !== false;
    }
}
