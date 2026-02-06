<?php

namespace App\Controllers;

use App\Services\LogService;
use App\Services\ProcesadorTablas;
use App\Services\MaterializadorImagenes;
use App\Services\TablasLiveService;
use App\Models\EstadoTracker;
use App\Models\ImagenesIndex;

class ProcesarController extends BaseController
{
    public function procesar()
    {
        // Suprimir warnings y errores para que no se mezclen con el JSON
        error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
        ini_set('display_errors', 0);
        
        // Iniciar buffer de salida para capturar cualquier output inesperado
        ob_start();
        
        try {
            // En modo solo imágenes, no se procesa MySQL
            if (\App\Services\ConfigService::getWorkspaceMode() !== 'db_and_images') {
                ob_clean();
                $this->jsonResponse([
                    'success' => false,
                    'error' => 'Este workspace está en modo "solo imágenes". El procesamiento de tablas (MySQL) está deshabilitado.'
                ], 400);
            }

            // NO buscar tablas - solo usar las que están en el estado
            $estadoTracker = new EstadoTracker();
            $estadoProcesamiento = $estadoTracker->getEstado();
            
            if (empty($estadoProcesamiento)) {
                // Refrescar en vivo (por patrón) si aún no hay estado
                try {
                    $r = TablasLiveService::refrescar();
                    $estadoProcesamiento = $r['estado'] ?? [];
                } catch (\Throwable $e) {
                    $estadoProcesamiento = [];
                }

                if (empty($estadoProcesamiento)) {
                    ob_clean();
                    $this->jsonResponse([
                        'success' => false,
                        'error' => 'No se encontraron tablas para procesar. Revisa TABLE_PATTERN en la parametrización.'
                    ], 400);
                }
            }
            
            // Obtener lista de tablas del estado
            $tablasDelEstado = array_keys($estadoProcesamiento);

            // Ordenar despacho: tablas con menos registros (max_id) primero
            usort($tablasDelEstado, function ($a, $b) use ($estadoProcesamiento) {
                $ea = $estadoProcesamiento[$a] ?? [];
                $eb = $estadoProcesamiento[$b] ?? [];
                $ma = isset($ea['max_id']) ? (int)$ea['max_id'] : PHP_INT_MAX;
                $mb = isset($eb['max_id']) ? (int)$eb['max_id'] : PHP_INT_MAX;
                if ($ma === $mb) {
                    return strcmp((string)$a, (string)$b);
                }
                return $ma <=> $mb;
            });
            
            // Inicializar procesadores
            $procesador = new ProcesadorTablas();
            $materializador = new MaterializadorImagenes();
            
            $columnasImagen = $procesador->getColumnasImagen();
            $campoIdentificador = $procesador->getCampoIdentificador();
            $campoUsrId = $procesador->getCampoUsrId();
            $campoFecha = $procesador->getCampoFecha();
            $campoResultado = $procesador->getCampoResultado();
            
            $tablaProcesada = null;
            $registroProcesado = false;
            $totalImagenes = 0;
            $totalDuplicadas = 0;
            $duplicadasCols = [];
            $mensaje = '';
            $registroId = null;

            // Buscar la primera tabla con registros pendientes (solo usando el estado, sin consultar BD)
            foreach ($tablasDelEstado as $tabla) {
                // Verificar si faltan registros (solo usando el estado)
                if (!$estadoTracker->faltanRegistros($tabla)) {
                    continue;
                }
                
                $tablaProcesada = $tabla;
                LogService::append([
                    'type' => 'info',
                    'message' => 'Procesando tabla: ' . $tabla,
                ]);

                // Obtener siguiente registro
                $resultadoRegistro = $procesador->obtenerSiguienteRegistro($tabla);

                if (!$resultadoRegistro['success']) {
                    $mensaje = "Error en tabla {$tabla}: " . $resultadoRegistro['error'];
                    LogService::append(['type' => 'error', 'message' => $mensaje]);
                    continue;
                }

                if ($resultadoRegistro['registro'] === null) {
                    $mensaje = "No hay más registros en {$tabla}";
                    LogService::append(['type' => 'info', 'message' => $mensaje]);
                    continue;
                }
                
                $registro = $resultadoRegistro['registro'];
                $registroProcesado = true;

                // Capturar el índice (primary key) del registro procesado
                try {
                    $primaryKey = \App\Services\ConfigService::obtenerRequerido('PRIMARY_KEY');
                    if (isset($registro[$primaryKey])) {
                        $registroId = is_numeric($registro[$primaryKey]) ? (int)$registro[$primaryKey] : $registro[$primaryKey];
                    }
                } catch (\Exception $e) {
                    // Silencioso: no romper por falta de config
                    $registroId = null;
                }
                
                // Materializar imágenes
                $resultadosImagenes = $materializador->materializarImagenesRegistro(
                    $registro,
                    $columnasImagen,
                    $campoIdentificador,
                    $campoUsrId,
                    $campoFecha,
                    $campoResultado
                );
                
                $erroresImagenes = [];
                $rutasGuardadas = [];
                foreach ($resultadosImagenes as $columna => $resultadoImg) {
                    if (!empty($resultadoImg['duplicate'])) {
                        $totalDuplicadas++;
                        $duplicadasCols[] = (string)$columna;
                        continue;
                    }
                    if (($resultadoImg['success'] ?? false) === true) {
                        if (!empty($resultadoImg['ruta'])) {
                            $totalImagenes++;
                            $rutasGuardadas[] = $resultadoImg['ruta'];
                        }
                    } else {
                        $erroresImagenes[] = $columna . ': ' . ($resultadoImg['error'] ?? 'Error desconocido');
                    }
                }

                // Actualizar índice de imágenes/estadísticas sin reindexación completa
                $clasificacionStats = null;
                if (!empty($rutasGuardadas)) {
                    try {
                        $imagenesIndex = new ImagenesIndex();
                        $imagenesIndex->upsertDesdeRutas($rutasGuardadas, ImagenesIndex::resolverDirectorioBaseImagenes());
                        $clasificacionStats = $imagenesIndex->getStats(true);
                    } catch (\Exception $e) {
                        // Silencioso: no romper descarga por fallo de índice
                        $clasificacionStats = null;
                    }
                }
                
                $valorId = ($registroId !== null) ? (string) $registroId : '';
                if ($resultadoRegistro['faltan_registros']) {
                    $mensaje = $valorId !== '' ? "Registro procesado de {$tabla} (id: {$valorId}). Aún faltan registros." : "Registro procesado de {$tabla}. Aún faltan registros.";
                } else {
                    $mensaje = $valorId !== '' ? "Registro procesado de {$tabla} (id: {$valorId}). Tabla completada." : "Registro procesado de {$tabla}. Tabla completada.";
                }
                
                if (!empty($erroresImagenes)) {
                    $mensaje .= " Errores en imágenes: " . implode(', ', $erroresImagenes);
                }
                if ($totalDuplicadas > 0) {
                    $mensaje .= " Duplicadas omitidas: {$totalDuplicadas}.";
                }
                LogService::append([
                    'type' => $totalImagenes > 0 ? 'success' : 'info',
                    'message' => $mensaje,
                ]);
                break;
            }
            
            // Recargar estado desde el archivo (el ProcesadorTablas ya lo actualizó y guardó)
            $estadoTracker->recargarEstado();
            $estadoProcesamiento = $estadoTracker->getEstado();
            
            // Limpiar cualquier output inesperado antes de enviar JSON
            ob_clean();
            
            $this->jsonResponse([
                'success' => true,
                'tabla' => $tablaProcesada,
                'registro_procesado' => $registroProcesado,
                'registro_id' => $registroId,
                'total_imagenes' => $totalImagenes,
                'total_duplicadas' => $totalDuplicadas,
                'duplicadas_cols' => $duplicadasCols,
                'mensaje' => $mensaje,
                'faltan_registros' => $tablaProcesada ? $estadoTracker->faltanRegistros($tablaProcesada) : false,
                'estado' => $estadoProcesamiento,
                'clasificacion_stats' => $clasificacionStats
            ]);
            
        } catch (\Exception $e) {
            // Limpiar cualquier output inesperado antes de enviar JSON
            ob_clean();
            
            $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Estadísticas de descarga de tablas (registros descargados / pendientes) para el panel Procesando.
     */
    public function estadisticasDescarga()
    {
        try {
            if (\App\Services\ConfigService::getWorkspaceMode() !== 'db_and_images') {
                $this->jsonResponse([
                    'success' => true,
                    'stats' => [
                        'total_registros' => 0,
                        'registros_procesados' => 0,
                        'pendientes' => 0,
                        'tablas_total' => 0,
                        'tablas_completadas' => 0,
                    ],
                    'estado' => []
                ]);
                return;
            }

            $estadoTracker = new EstadoTracker();
            $estado = $estadoTracker->getEstado();

            $totalRegistros = 0;
            $registrosProcesados = 0;
            $tablasCompletadas = 0;

            foreach ($estado as $tabla => $info) {
                $maxId = (int)($info['max_id'] ?? 0);
                $ultimoId = (int)($info['ultimo_id'] ?? 0);
                $totalRegistros += $maxId;
                $registrosProcesados += $ultimoId;
                if (empty($info['faltan_registros'])) {
                    $tablasCompletadas++;
                }
            }

            $pendientes = max(0, $totalRegistros - $registrosProcesados);

            $this->jsonResponse([
                'success' => true,
                'stats' => [
                    'total_registros' => $totalRegistros,
                    'registros_procesados' => $registrosProcesados,
                    'pendientes' => $pendientes,
                    'tablas_total' => count($estado),
                    'tablas_completadas' => $tablasCompletadas,
                ],
                'estado' => $estado
            ]);
        } catch (\Exception $e) {
            $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
