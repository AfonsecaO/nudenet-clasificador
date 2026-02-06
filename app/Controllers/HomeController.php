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
            
            // Workspace por URL: para workers en paralelo (varias pestañas), enviar en cada API call
            $appWorkspaceSlug = null;
            if (\App\Services\WorkspaceService::hasRequestOverride()) {
                $appWorkspaceSlug = \App\Services\WorkspaceService::current();
            }
            $autoParam = isset($_GET['auto']) && in_array($_GET['auto'], ['descargar', 'clasificar'], true) ? $_GET['auto'] : null;

            // Preparar datos para la vista
            $data = [
                'mode' => $mode,
                'pattern' => ($mode === 'db_and_images') ? ConfigService::obtenerRequerido('TABLE_PATTERN') : '',
                'totalTablasEstado' => $totalTablasEstado,
                'tablasDelEstado' => $tablasDelEstado,
                'estadoProcesamiento' => $estadoProcesamiento,
                'app_workspace_slug' => $appWorkspaceSlug,
                'auto_param' => $autoParam,
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
