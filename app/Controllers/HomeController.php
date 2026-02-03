<?php

namespace App\Controllers;

use App\Models\EstadoTracker;
use App\Services\ConfigService;
use App\Services\TablasLiveService;

/**
 * Controlador Principal (Home)
 * 
 * Maneja la vista principal de la aplicación.
 */
class HomeController extends BaseController
{
    /**
     * Muestra la vista principal
     */
    public function index()
    {
        try {
            $mode = ConfigService::getWorkspaceMode();
            // Refrescar tablas en vivo (solo en modo DB)
            if ($mode === 'db_and_images') {
                try {
                    TablasLiveService::refrescar();
                } catch (\Throwable $e) {
                    // Silencioso: si la BD está temporalmente caída, renderizar lo que haya en el estado
                }
            }

            $estadoTracker = new EstadoTracker();
            $estadoProcesamiento = $estadoTracker->getEstado();
            
            // Si hay estado guardado, extraer las tablas para mostrarlas
            $tablasDelEstado = [];
            $totalTablasEstado = 0;
            if (!empty($estadoProcesamiento)) {
                $tablasDelEstado = array_keys($estadoProcesamiento);
                
                // Ordenar: primero las pendientes, luego las completadas
                usort($tablasDelEstado, function($a, $b) use ($estadoProcesamiento) {
                    $estadoA = $estadoProcesamiento[$a] ?? null;
                    $estadoB = $estadoProcesamiento[$b] ?? null;
                    
                    $faltanA = $estadoA['faltan_registros'] ?? true;
                    $faltanB = $estadoB['faltan_registros'] ?? true;
                    
                    // Si ambas tienen el mismo estado, mantener orden original
                    if ($faltanA === $faltanB) {
                        return 0;
                    }
                    
                    // Las pendientes (true) van primero, las completadas (false) van al final
                    return $faltanA ? -1 : 1;
                });
                
                $totalTablasEstado = count($tablasDelEstado);
            }
            
            // Preparar datos para la vista
            $data = [
                'mode' => $mode,
                'pattern' => ($mode === 'db_and_images') ? ConfigService::obtenerRequerido('TABLE_PATTERN') : '',
                'totalTablasEstado' => $totalTablasEstado,
                'tablasDelEstado' => $tablasDelEstado,
                'estadoProcesamiento' => $estadoProcesamiento
            ];
            
            // Renderizar vista
            $this->render('index', $data);
            
        } catch (\Exception $e) {
            $this->render('errors/config', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
}
