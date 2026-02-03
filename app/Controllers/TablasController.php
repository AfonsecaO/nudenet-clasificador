<?php

namespace App\Controllers;

use App\Models\TablaBuscador;
use App\Models\EstadoTracker;
use App\Models\Database;

class TablasController extends BaseController
{
    public function obtener()
    {
        try {
            // Buscar tablas
            $buscador = new TablaBuscador();
            $resultadoBusqueda = $buscador->buscarTablas();
            
            if (!$resultadoBusqueda['success']) {
                $this->jsonResponse([
                    'success' => false,
                    'error' => $resultadoBusqueda['error']
                ], 400);
            }
            
            if ($resultadoBusqueda['total'] == 0) {
                $this->jsonResponse([
                    'success' => true,
                    'total' => 0,
                    'tablas' => [],
                    'estado' => []
                ]);
            }
            
            // Sincronizar tablas (esto agrega nuevas y elimina las que ya no existen)
            $estadoTracker = new EstadoTracker();
            $estadoTracker->sincronizarTablas($resultadoBusqueda['tablas']);
            
            // Obtener instancia de Database
            $database = new Database();
            $conn = $database->getConnection();
            
            // Obtener primary key del env
            $primaryKey = \App\Services\ConfigService::obtenerRequerido('PRIMARY_KEY');
            
            // Contar registros en cada tabla
            $tablasConContador = [];
            foreach ($resultadoBusqueda['tablas'] as $tabla) {
                // Verificar si la tabla existe
                if (!$database->tablaExiste($tabla)) {
                    continue;
                }
                
                try {
                    // Obtener el ID máximo (no COUNT, porque los IDs pueden tener gaps)
                    $sql = "SELECT MAX(`{$primaryKey}`) as max_id FROM `{$tabla}`";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute();
                    $resultado = $stmt->fetch();
                    $maxId = $resultado['max_id'] !== null ? (int)$resultado['max_id'] : 0;
                    
                    // Actualizar el max_id en el estado (esto preserva automáticamente el ultimo_id)
                    $estadoTracker->actualizarMaxId($tabla, $maxId);
                    
                    $tablasConContador[] = [
                        'tabla' => $tabla,
                        'max_id' => $maxId
                    ];
                } catch (\Exception $e) {
                    // Si hay error al obtener max_id, continuar con la siguiente tabla
                    continue;
                }
            }
            
            // Recargar estado actualizado
            $estadoProcesamiento = $estadoTracker->getEstado();
            
            $this->jsonResponse([
                'success' => true,
                'total' => count($tablasConContador),
                'tablas' => $tablasConContador,
                'estado' => $estadoProcesamiento
            ]);
            
        } catch (\Exception $e) {
            $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
