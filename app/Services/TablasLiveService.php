<?php

namespace App\Services;

use App\Models\Database;
use App\Models\EstadoTracker;
use App\Models\TablaBuscador;

class TablasLiveService
{
    /**
     * Refresca el estado de tablas en vivo según TABLE_PATTERN:
     * - descubre tablas
     * - actualiza max_id por tabla
     * - sincroniza tables_state / tables_index (SQLite)
     */
    public static function refrescar(): array
    {
        // En modo "solo imágenes" no hay DB/tablas que refrescar
        if (ConfigService::getWorkspaceMode() !== 'db_and_images') {
            return [
                'success' => true,
                'tablas' => [],
                'estado' => [],
                'pattern' => null
            ];
        }

        $buscador = new TablaBuscador();
        $resultadoBusqueda = $buscador->buscarTablas();
        if (!($resultadoBusqueda['success'] ?? false)) {
            return [
                'success' => false,
                'error' => $resultadoBusqueda['error'] ?? 'Error al buscar tablas',
                'tablas' => [],
                'estado' => []
            ];
        }

        $estadoTracker = new EstadoTracker();
        $tablas = $resultadoBusqueda['tablas'] ?? [];
        $estadoTracker->sincronizarTablas($tablas);

        $database = new Database();
        $conn = $database->getConnection();
        $primaryKey = ConfigService::obtenerRequerido('PRIMARY_KEY');

        $tablasConContador = [];
        foreach ($tablas as $tabla) {
            if (!$database->tablaExiste($tabla)) continue;
            try {
                $sql = "SELECT MAX(`{$primaryKey}`) as max_id FROM `{$tabla}`";
                $stmt = $conn->prepare($sql);
                $stmt->execute();
                $row = $stmt->fetch();
                $maxId = ($row && $row['max_id'] !== null) ? (int)$row['max_id'] : 0;
                $estadoTracker->actualizarMaxId($tabla, $maxId);
                $tablasConContador[] = ['tabla' => $tabla, 'max_id' => $maxId];
            } catch (\Throwable $e) {
                // continuar
            }
        }

        usort($tablasConContador, function ($a, $b) {
            $ma = (int)($a['max_id'] ?? 0);
            $mb = (int)($b['max_id'] ?? 0);
            if ($ma === $mb) {
                return strcmp((string)($a['tabla'] ?? ''), (string)($b['tabla'] ?? ''));
            }
            return $ma <=> $mb;
        });

        $estadoTracker->setIndiceTablas($tablasConContador, $resultadoBusqueda['pattern'] ?? null);
        $estadoProcesamiento = $estadoTracker->getEstado();

        return [
            'success' => true,
            'tablas' => $tablasConContador,
            'estado' => $estadoProcesamiento,
            'pattern' => $resultadoBusqueda['pattern'] ?? null
        ];
    }
}

