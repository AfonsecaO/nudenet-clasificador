<?php

namespace App\Controllers;

use App\Services\AppConnection;
use App\Services\LogService;
use App\Services\ProcesadorTablas;
use App\Services\MaterializadorImagenes;
use App\Services\TablasLiveService;
use App\Services\WorkspaceService;
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
                    'error' => 'Este workspace está en modo "solo imágenes". El procesamiento de tablas (MySQL) está deshabilitado.',
                    'log_items' => LogService::tail(50),
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
                        'error' => 'No se encontraron tablas para procesar. Revisa TABLE_PATTERN en la parametrización.',
                        'log_items' => LogService::tail(50),
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
            $clasificacionStats = null;

            $batchSize = \App\Services\StorageEngineConfig::getRegistrosDescarga();
            $procesadosEnPeticion = 0;
            $range = null;

            foreach ($tablasDelEstado as $tabla) {
                if (!$estadoTracker->faltanRegistros($tabla)) {
                    continue;
                }
                $maxIdOrigen = $procesador->obtenerMaxIdDesdeOrigen($tabla);
                if ($maxIdOrigen !== null) {
                    $estadoTracker->actualizarMaxId($tabla, $maxIdOrigen);
                }
                $range = $estadoTracker->claimNextBatch($tabla, $batchSize);
                if ($range === null) {
                    continue;
                }
                $tablaProcesada = $tabla;
                $registros = $procesador->obtenerRegistrosEnRango($tabla, $range['id_min'], $range['id_max']);
                if (empty($registros)) {
                    LogService::append(['type' => 'warning', 'message' => "Tabla {$tabla}: no se obtuvieron registros en rango [{$range['id_min']}, {$range['id_max']}]"]);
                    $estadoTracker->markBatchCompleted($tabla, $range['id_min'], $range['id_max']);
                    $estadoTracker->purgeCompletedClaims();
                    break;
                }

                try {
                    $primaryKey = \App\Services\ConfigService::obtenerRequerido('PRIMARY_KEY');
                    $batchRegistros = 0;
                    $batchImagenesNuevas = 0;
                    $batchDuplicadas = 0;
                    $batchErroresImagen = 0;
                    $batchRegistrosConError = 0;

                    foreach ($registros as $registro) {
                        $registroProcesado = true;
                        $procesadosEnPeticion++;
                        $batchRegistros++;
                        try {
                            $registroId = isset($registro[$primaryKey]) ? (is_numeric($registro[$primaryKey]) ? (int) $registro[$primaryKey] : $registro[$primaryKey]) : null;
                        } catch (\Exception $e) {
                            $registroId = null;
                        }

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
                        $rawMd5PorRuta = [];
                        foreach ($resultadosImagenes as $columna => $resultadoImg) {
                            if (!empty($resultadoImg['duplicate'])) {
                                $totalDuplicadas++;
                                $batchDuplicadas++;
                                $duplicadasCols[] = (string) $columna;
                                continue;
                            }
                            if (($resultadoImg['success'] ?? false) === true) {
                                if (!empty($resultadoImg['ruta'])) {
                                    $totalImagenes++;
                                    $batchImagenesNuevas++;
                                    $rutasGuardadas[] = $resultadoImg['ruta'];
                                    if (!empty($resultadoImg['raw_md5'])) {
                                        $rawMd5PorRuta[$resultadoImg['ruta']] = $resultadoImg['raw_md5'];
                                    }
                                }
                            } else {
                                $erroresImagenes[] = $columna . ': ' . ($resultadoImg['error'] ?? 'Error desconocido');
                            }
                        }

                        $countErroresRegistro = count($erroresImagenes);
                        if ($countErroresRegistro > 0) {
                            $batchErroresImagen += $countErroresRegistro;
                            $batchRegistrosConError++;
                        }

                        if (!empty($rutasGuardadas)) {
                            try {
                                $imagenesIndex = new ImagenesIndex();
                                $directorioBase = ImagenesIndex::resolverDirectorioBaseImagenes();
                                $imagenesIndex->upsertDesdeRutas($rutasGuardadas, $directorioBase, $rawMd5PorRuta);
                                $clasificacionStats = $imagenesIndex->getStats(true);
                            } catch (\Exception $e) {
                                $clasificacionStats = null;
                            }
                        }
                    }

                    $mensajeBatch = sprintf(
                        '%s [%s–%s]: %d registros, %d imágenes nuevas, %d duplicadas, %d errores (%d reg. con error)',
                        $tablaProcesada,
                        $range['id_min'],
                        $range['id_max'],
                        $batchRegistros,
                        $batchImagenesNuevas,
                        $batchDuplicadas,
                        $batchErroresImagen,
                        $batchRegistrosConError
                    );
                    LogService::append([
                        'type' => $batchImagenesNuevas > 0 ? 'success' : ($batchErroresImagen > 0 ? 'warning' : 'info'),
                        'message' => $mensajeBatch,
                    ]);

                    $estadoTracker->markBatchCompleted($tablaProcesada, $range['id_min'], $range['id_max']);
                    $estadoTracker->purgeCompletedClaims();
                } catch (\Throwable $e) {
                    LogService::append(['type' => 'error', 'message' => "Error procesando lote {$tablaProcesada} [{$range['id_min']}-{$range['id_max']}]: " . $e->getMessage()]);
                    // No llamar markBatchCompleted: el claim queda in_progress y será reclaimable como stale
                }
                break;
            }

            $estadoTracker->recargarEstado();
            $estadoProcesamiento = $estadoTracker->getEstado();
            $totalRegistros = 0;
            $registrosProcesados = 0;
            foreach ($estadoProcesamiento as $tabla => $info) {
                $totalRegistros += (int)($info['max_id'] ?? 0);
                $registrosProcesados += (int)($info['ultimo_id'] ?? 0);
            }
            $pendientesDescarga = max(0, $totalRegistros - $registrosProcesados);

            $imagenesTotal = null;
            $imagenesPendientesModeracion = null;
            try {
                $wsSlug = WorkspaceService::current();
                if ($wsSlug !== null && $wsSlug !== '') {
                    $pdo = AppConnection::get();
                    $tImages = AppConnection::table('images');
                    $st = $pdo->prepare("SELECT COUNT(*) FROM {$tImages} WHERE workspace_slug = ?");
                    $st->execute([$wsSlug]);
                    $imagenesTotal = (int) $st->fetchColumn();
                    $condMod = AppConnection::getCurrentDriver() === 'mysql'
                ? 'moderation_analyzed_at IS NULL'
                : '(moderation_analyzed_at IS NULL OR moderation_analyzed_at = \'\')';
            $stMod = $pdo->prepare("SELECT COUNT(*) FROM {$tImages} WHERE workspace_slug = ? AND {$condMod}");
                    $stMod->execute([$wsSlug]);
                    $imagenesPendientesModeracion = (int) $stMod->fetchColumn();
                }
            } catch (\Throwable $e) {
                // no romper la respuesta; los indicadores quedarán null
            }

            // Limpiar cualquier output inesperado antes de enviar JSON
            ob_clean();

            // Incluir log en la misma respuesta para que la UI lo pinte sin polling
            $logItems = LogService::tail(50);

            $this->jsonResponse([
                'success' => true,
                'tabla' => $tablaProcesada,
                'registro_procesado' => $registroProcesado,
                'registros_en_peticion' => $procesadosEnPeticion,
                'pendientes' => $pendientesDescarga,
                'registro_id' => $registroId,
                'total_imagenes' => $totalImagenes,
                'total_duplicadas' => $totalDuplicadas,
                'duplicadas_cols' => $duplicadasCols,
                'mensaje' => $mensaje,
                'faltan_registros' => $tablaProcesada ? $estadoTracker->faltanRegistros($tablaProcesada) : false,
                'estado' => $estadoProcesamiento,
                'clasificacion_stats' => $clasificacionStats,
                'log_items' => $logItems,
                'indicadores' => [
                    'registros_pendientes_descarga' => $pendientesDescarga,
                    'imagenes_total' => $imagenesTotal,
                    'imagenes_pendientes_moderacion' => $imagenesPendientesModeracion,
                ],
            ]);
            
        } catch (\Exception $e) {
            // Limpiar cualquier output inesperado antes de enviar JSON
            ob_clean();
            $logItems = LogService::tail(50);
            $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'log_items' => $logItems,
            ], 500);
        }
    }

    /**
     * Estadísticas de descarga de tablas (registros descargados / pendientes) para el panel Procesando.
     * Acepta workspace por GET para peticiones desde el index.
     */
    public function estadisticasDescarga()
    {
        try {
            if (isset($_GET['workspace']) && trim((string) $_GET['workspace']) !== '') {
                $reqWs = \App\Services\WorkspaceService::slugify(trim((string) $_GET['workspace']));
                if ($reqWs !== '' && \App\Services\WorkspaceService::isValidSlug($reqWs) && \App\Services\WorkspaceService::exists($reqWs)) {
                    \App\Services\WorkspaceService::setCurrent($reqWs);
                }
            }
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
