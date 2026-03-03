<?php

namespace App\Models;

class EstadoTracker
{
    private const STALE_MINUTES = 10;
    private const CLAIMS_RETENTION_HOURS = 24;
    private const PURGE_LIMIT = 500;

    private $pdo;

    private $tTablesState;
    private $tTablesIndex;
    private $tBatchClaims;

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
        $this->tBatchClaims = \App\Services\AppConnection::table('batch_claims');
    }

    /**
     * Obtiene el último ID procesado para una tabla
     */
    public function getUltimoId($tabla)
    {
        $stmt = $this->pdo->prepare('SELECT last_processed_id FROM ' . $this->tTablesState . ' WHERE workspace_slug = :ws AND table_name = :t');
        $stmt->execute([':ws' => $this->ws(), ':t' => $tabla]);
        $v = $stmt->fetchColumn();
        return $v !== false ? (int)$v : 0;
    }

    /**
     * Actualiza el último ID (siguiente id a intentar) para una tabla.
     * Si $faltanRegistros === null: solo actualiza last_processed_id y last_updated_at (no consulta max_id).
     */
    public function actualizarUltimoId($tabla, $id, $faltanRegistros = true)
    {
        $this->inicializarTabla($tabla);
        $now = date('Y-m-d H:i:s');
        $ws = $this->ws();

        if ($faltanRegistros === null) {
            $upd = $this->pdo->prepare("
                UPDATE " . $this->tTablesState . "
                SET last_processed_id = :u, last_updated_at = :ua
                WHERE workspace_slug = :ws AND table_name = :t
            ");
            $upd->execute([
                ':u' => (int)$id,
                ':ua' => $now,
                ':ws' => $ws,
                ':t' => $tabla
            ]);
            return;
        }

        $stmt = $this->pdo->prepare('SELECT max_id FROM ' . $this->tTablesState . ' WHERE workspace_slug = :ws AND table_name = :t');
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
            SET last_processed_id = :u, has_pending = :f, last_updated_at = :ua
            WHERE workspace_slug = :ws AND table_name = :t
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

        $stmt = $this->pdo->prepare('SELECT last_processed_id FROM ' . $this->tTablesState . ' WHERE workspace_slug = :ws AND table_name = :t');
        $stmt->execute([':ws' => $ws, ':t' => $tabla]);
        $ultimo = $stmt->fetchColumn();
        $ultimo = ($ultimo !== false) ? (int)$ultimo : 0;
        $faltan = ($ultimo < (int)$maxId) ? 1 : 0;

        $upd = $this->pdo->prepare("
            UPDATE " . $this->tTablesState . "
            SET max_id = :m, has_pending = :f, last_count_at = :uac
            WHERE workspace_slug = :ws AND table_name = :t
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
                INSERT INTO " . $this->tTablesIndex . "(workspace_slug, table_name, max_id) VALUES(:ws, :t, :m)
                ON DUPLICATE KEY UPDATE max_id = VALUES(max_id)
            ");
        } else {
            $up2 = $this->pdo->prepare("
                INSERT INTO " . $this->tTablesIndex . "(workspace_slug, table_name, max_id) VALUES(:ws, :t, :m)
                ON CONFLICT(workspace_slug, table_name) DO UPDATE SET max_id=excluded.max_id
            ");
        }
        $up2->execute([':ws' => $ws, ':t' => $tabla, ':m' => (int)$maxId]);
    }

    /**
     * Verifica si faltan registros para una tabla
     */
    public function faltanRegistros($tabla)
    {
        $stmt = $this->pdo->prepare('SELECT has_pending FROM ' . $this->tTablesState . ' WHERE workspace_slug = :ws AND table_name = :t');
        $stmt->execute([':ws' => $this->ws(), ':t' => $tabla]);
        $v = $stmt->fetchColumn();
        if ($v === false) return true;
        return ((int)$v) !== 0;
    }

    /**
     * Obtiene todo el estado (keys compatibles con consumidores existentes)
     */
    public function getEstado()
    {
        $stmt = $this->pdo->prepare('SELECT table_name, last_processed_id, max_id, has_pending, last_updated_at, last_count_at FROM ' . $this->tTablesState . ' WHERE workspace_slug = :ws');
        $stmt->execute([':ws' => $this->ws()]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $tabla = (string)($r['table_name'] ?? '');
            if ($tabla === '') continue;
            $out[$tabla] = [
                'ultimo_id' => (int)($r['last_processed_id'] ?? 0),
                'max_id' => (int)($r['max_id'] ?? 0),
                'faltan_registros' => ((int)($r['has_pending'] ?? 1)) !== 0,
                'ultima_actualizacion' => $r['last_updated_at'] ?? null,
                'ultima_actualizacion_contador' => $r['last_count_at'] ?? null
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
        $stmt = $this->pdo->prepare('SELECT table_name, max_id FROM ' . $this->tTablesIndex . ' WHERE workspace_slug = :ws ORDER BY max_id ASC');
        $stmt->execute([':ws' => $ws]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $tablas = [];
        foreach ($rows as $r) {
            $tablas[] = [
                'tabla' => (string)$r['table_name'],
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
            $stmt = $this->pdo->prepare('INSERT INTO ' . $this->tTablesIndex . '(workspace_slug, table_name, max_id) VALUES(:ws, :t, :m)');
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
     * Repara tablas marcadas como completadas (has_pending=0) que tengan last_processed_id < max_id.
     */
    public function repararUltimoIdEnCompletadas(): void
    {
        $ws = $this->ws();
        if ($ws === '') {
            return;
        }
        $upd = $this->pdo->prepare("
            UPDATE " . $this->tTablesState . "
            SET last_processed_id = max_id
            WHERE workspace_slug = :ws AND (COALESCE(has_pending, 1) = 0) AND last_processed_id < max_id
        ");
        $upd->execute([':ws' => $ws]);
    }

    /**
     * Inicializa el estado para una tabla si no existe
     */
    public function inicializarTabla($tabla)
    {
        $ws = $this->ws();
        $driver = \App\Services\AppConnection::getCurrentDriver();
        if ($driver === 'mysql') {
            $stmt = $this->pdo->prepare('INSERT IGNORE INTO ' . $this->tTablesState . '(workspace_slug, table_name) VALUES(:ws, :t)');
        } else {
            $stmt = $this->pdo->prepare('INSERT INTO ' . $this->tTablesState . '(workspace_slug, table_name) VALUES(:ws, :t) ON CONFLICT(workspace_slug, table_name) DO NOTHING');
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

        $stmt = $this->pdo->prepare('SELECT table_name FROM ' . $this->tTablesState . ' WHERE workspace_slug = :ws');
        $stmt->execute([':ws' => $ws]);
        $antes = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $antesSet = [];
        foreach ($antes as $r) {
            $antesSet[(string)$r['table_name']] = true;
        }

        $cambios = false;
        foreach ($tablasExistentes as $t) {
            if (!isset($antesSet[$t])) {
                $this->inicializarTabla($t);
                $cambios = true;
            }
        }

        foreach (array_keys($antesSet) as $t) {
            if (!isset($set[$t])) {
                $del = $this->pdo->prepare('DELETE FROM ' . $this->tTablesState . ' WHERE workspace_slug = :ws AND table_name = :t');
                $del->execute([':ws' => $ws, ':t' => $t]);
                $del2 = $this->pdo->prepare('DELETE FROM ' . $this->tTablesIndex . ' WHERE workspace_slug = :ws AND table_name = :t');
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
        $stmt = $this->pdo->prepare('SELECT 1 FROM ' . $this->tTablesState . ' WHERE workspace_slug = :ws AND table_name = :t LIMIT 1');
        $stmt->execute([':ws' => $this->ws(), ':t' => $tabla]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Reserva atómicamente un lote para procesar: primero intenta reclamar un claim stale (in_progress antiguo),
     * si no hay, inserta un nuevo rango. Devuelve ['id_min' => int, 'id_max' => int] o null si no hay trabajo.
     */
    public function claimNextBatch(string $tabla, int $batchSize): ?array
    {
        $this->inicializarTabla($tabla);
        $ws = $this->ws();
        $now = date('Y-m-d H:i:s');
        $staleThreshold = date('Y-m-d H:i:s', strtotime('-' . self::STALE_MINUTES . ' minutes'));

        $driver = \App\Services\AppConnection::getCurrentDriver();

        // 1) Reclaim stale: in_progress con claimed_at antiguo
        $stmt = $this->pdo->prepare(
            'SELECT id_min, id_max FROM ' . $this->tBatchClaims . '
            WHERE workspace_slug = :ws AND table_name = :t AND status = \'in_progress\' AND claimed_at < :stale
            ORDER BY id_min ASC LIMIT 1'
        );
        $stmt->execute([':ws' => $ws, ':t' => $tabla, ':stale' => $staleThreshold]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row !== false) {
            $idMin = (int) $row['id_min'];
            $idMax = (int) $row['id_max'];
            $upd = $this->pdo->prepare(
                'UPDATE ' . $this->tBatchClaims . ' SET claimed_at = :now
                WHERE workspace_slug = :ws AND table_name = :t AND id_min = :id_min'
            );
            $upd->execute([':now' => $now, ':ws' => $ws, ':t' => $tabla, ':id_min' => $idMin]);
            return ['id_min' => $idMin, 'id_max' => $idMax];
        }

        // 2) Leer max_id y last_processed_id para calcular siguiente rango
        $stmt = $this->pdo->prepare(
            'SELECT last_processed_id, max_id FROM ' . $this->tTablesState . ' WHERE workspace_slug = :ws AND table_name = :t'
        );
        $stmt->execute([':ws' => $ws, ':t' => $tabla]);
        $stateRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        $lastProcessedId = $stateRow ? (int) ($stateRow['last_processed_id'] ?? 0) : 0;
        $maxId = $stateRow ? (int) ($stateRow['max_id'] ?? 0) : 0;

        $maxIdMaxFromClaims = null;
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(id_max), 0) as m FROM ' . $this->tBatchClaims . ' WHERE workspace_slug = :ws AND table_name = :t'
        );
        $stmt->execute([':ws' => $ws, ':t' => $tabla]);
        $mm = $stmt->fetchColumn();
        $maxIdMaxFromClaims = $mm !== false ? (int) $mm : 0;

        $nextMin = max($lastProcessedId, $maxIdMaxFromClaims) + 1;
        if ($nextMin > $maxId || $maxId === 0) {
            return null;
        }
        $nextMax = min($nextMin + $batchSize - 1, $maxId);

        $maxAttempts = 5;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                $ins = $this->pdo->prepare(
                    'INSERT INTO ' . $this->tBatchClaims . ' (workspace_slug, table_name, id_min, id_max, status, claimed_at)
                    VALUES (:ws, :t, :id_min, :id_max, \'in_progress\', :now)'
                );
                $ins->execute([
                    ':ws' => $ws,
                    ':t' => $tabla,
                    ':id_min' => $nextMin,
                    ':id_max' => $nextMax,
                    ':now' => $now,
                ]);
                return ['id_min' => $nextMin, 'id_max' => $nextMax];
            } catch (\PDOException $e) {
                if ($driver === 'mysql' && $e->getCode() == 23000) {
                    // Duplicate key: otro worker tomó el rango; recalcular next_min
                    $stmt = $this->pdo->prepare(
                        'SELECT COALESCE(MAX(id_max), 0) as m FROM ' . $this->tBatchClaims . ' WHERE workspace_slug = :ws AND table_name = :t'
                    );
                    $stmt->execute([':ws' => $ws, ':t' => $tabla]);
                    $maxIdMaxFromClaims = (int) $stmt->fetchColumn();
                    $nextMin = max($lastProcessedId, $maxIdMaxFromClaims) + 1;
                    if ($nextMin > $maxId) {
                        return null;
                    }
                    $nextMax = min($nextMin + $batchSize - 1, $maxId);
                    continue;
                }
                if ($driver === 'sqlite' && strpos($e->getMessage(), 'UNIQUE') !== false) {
                    $stmt = $this->pdo->prepare(
                        'SELECT COALESCE(MAX(id_max), 0) as m FROM ' . $this->tBatchClaims . ' WHERE workspace_slug = :ws AND table_name = :t'
                    );
                    $stmt->execute([':ws' => $ws, ':t' => $tabla]);
                    $maxIdMaxFromClaims = (int) $stmt->fetchColumn();
                    $nextMin = max($lastProcessedId, $maxIdMaxFromClaims) + 1;
                    if ($nextMin > $maxId) {
                        return null;
                    }
                    $nextMax = min($nextMin + $batchSize - 1, $maxId);
                    continue;
                }
                throw $e;
            }
        }
        return null;
    }

    /**
     * Marca un lote como completado y sincroniza table_states (last_processed_id, has_pending).
     */
    public function markBatchCompleted(string $tabla, int $idMin, int $idMax): void
    {
        $ws = $this->ws();
        $now = date('Y-m-d H:i:s');

        $upd = $this->pdo->prepare(
            'UPDATE ' . $this->tBatchClaims . ' SET status = \'completed\', completed_at = :now
            WHERE workspace_slug = :ws AND table_name = :t AND id_min = :id_min AND id_max = :id_max'
        );
        $upd->execute([':now' => $now, ':ws' => $ws, ':t' => $tabla, ':id_min' => $idMin, ':id_max' => $idMax]);

        $stmt = $this->pdo->prepare('SELECT max_id FROM ' . $this->tTablesState . ' WHERE workspace_slug = :ws AND table_name = :t');
        $stmt->execute([':ws' => $ws, ':t' => $tabla]);
        $maxId = $stmt->fetchColumn();
        $maxId = ($maxId !== false) ? (int) $maxId : 0;
        $hasPending = $maxId > $idMax ? 1 : 0;

        $updState = $this->pdo->prepare(
            'UPDATE ' . $this->tTablesState . ' SET last_processed_id = :u, has_pending = :f, last_updated_at = :ua
            WHERE workspace_slug = :ws AND table_name = :t'
        );
        $updState->execute([
            ':u' => $idMax,
            ':f' => $hasPending,
            ':ua' => $now,
            ':ws' => $ws,
            ':t' => $tabla,
        ]);
    }

    /**
     * Purga claims completados o fallidos con antigüedad mayor a retention (horas). Mantiene la tabla transaccional.
     * @return int Número de filas eliminadas
     */
    public function purgeCompletedClaims(int $retentionHours = self::CLAIMS_RETENTION_HOURS, int $limit = self::PURGE_LIMIT): int
    {
        $threshold = date('Y-m-d H:i:s', strtotime('-' . $retentionHours . ' hours'));
        $driver = \App\Services\AppConnection::getCurrentDriver();
        $ws = $this->ws();

        if ($driver === 'mysql') {
            $del = $this->pdo->prepare(
                'DELETE FROM ' . $this->tBatchClaims . '
                WHERE workspace_slug = :ws AND status IN (\'completed\', \'failed\')
                AND (completed_at IS NOT NULL AND completed_at < :threshold)
                ORDER BY completed_at ASC LIMIT ' . (int) $limit
            );
            $del->execute([':ws' => $ws, ':threshold' => $threshold]);
            return $del->rowCount();
        }

        $sel = $this->pdo->prepare(
            'SELECT workspace_slug, table_name, id_min FROM ' . $this->tBatchClaims . '
            WHERE workspace_slug = :ws AND status IN (\'completed\', \'failed\')
            AND completed_at IS NOT NULL AND completed_at < :threshold
            ORDER BY completed_at ASC LIMIT ' . (int) $limit
        );
        $sel->execute([':ws' => $ws, ':threshold' => $threshold]);
        $rows = $sel->fetchAll(\PDO::FETCH_ASSOC);
        if (empty($rows)) {
            return 0;
        }
        $deleted = 0;
        $del = $this->pdo->prepare(
            'DELETE FROM ' . $this->tBatchClaims . '
            WHERE workspace_slug = :ws AND table_name = :t AND id_min = :id_min'
        );
        foreach ($rows as $r) {
            $del->execute([':ws' => $r['workspace_slug'], ':t' => $r['table_name'], ':id_min' => $r['id_min']]);
            $deleted += $del->rowCount();
        }
        return $deleted;
    }
}
