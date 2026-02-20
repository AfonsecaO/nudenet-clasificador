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
            $clasificacionStats = null;

            $limiteRegistros = 1;
            try {
                $n = (int) \App\Services\ConfigService::obtenerOpcional('MATERIALIZAR_REGISTROS_POR_PETICION', '1');
                $limiteRegistros = max(1, min(5, $n));
            } catch (\Throwable $e) {
                $limiteRegistros = 1;
            }

            $procesadosEnPeticion = 0;
            while ($procesadosEnPeticion < $limiteRegistros) {
                $avanzado = false;
                foreach ($tablasDelEstado as $tabla) {
                    if (!$estadoTracker->faltanRegistros($tabla)) {
                        continue;
                    }
                    $tablaProcesada = $tabla;
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
                    $avanzado = true;

                    try {
                        $primaryKey = \App\Services\ConfigService::obtenerRequerido('PRIMARY_KEY');
                        $registroId = isset($registro[$primaryKey]) ? (is_numeric($registro[$primaryKey]) ? (int)$registro[$primaryKey] : $registro[$primaryKey]) : null;
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
                            $duplicadasCols[] = (string)$columna;
                            continue;
                        }
                        if (($resultadoImg['success'] ?? false) === true) {
                            if (!empty($resultadoImg['ruta'])) {
                                $totalImagenes++;
                                $rutasGuardadas[] = $resultadoImg['ruta'];
                                if (!empty($resultadoImg['raw_md5'])) {
                                    $rawMd5PorRuta[$resultadoImg['ruta']] = $resultadoImg['raw_md5'];
                                }
                            }
                        } else {
                            $erroresImagenes[] = $columna . ': ' . ($resultadoImg['error'] ?? 'Error desconocido');
                        }
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

                    $valorId = ($registroId !== null) ? (string) $registroId : '—';
                    $countErrores = count($erroresImagenes);
                    $mensaje = $tabla . ' | ID:' . $valorId . ' | M:' . $totalImagenes . ' | E:' . $countErrores;
                    if (!$resultadoRegistro['faltan_registros']) {
                        $mensaje .= ' (tabla completada)';
                    }
                    LogService::append([
                        'type' => $totalImagenes > 0 ? 'success' : ($countErrores > 0 ? 'warning' : 'info'),
                        'message' => $mensaje,
                    ]);

                    $procesadosEnPeticion++;
                    $estadoTracker->recargarEstado();
                    if ($procesadosEnPeticion >= $limiteRegistros) {
                        break 2;
                    }
                }
                if (!$avanzado) {
                    break;
                }
            }
            
            // Recargar estado desde el archivo (el ProcesadorTablas ya lo actualizó y guardó)
            $estadoTracker->recargarEstado();
            $estadoProcesamiento = $estadoTracker->getEstado();
            $totalRegistros = 0;
            $registrosProcesados = 0;
            foreach ($estadoProcesamiento as $tabla => $info) {
                $totalRegistros += (int)($info['max_id'] ?? 0);
                $registrosProcesados += (int)($info['ultimo_id'] ?? 0);
            }
            $pendientesDescarga = max(0, $totalRegistros - $registrosProcesados);

            // Limpiar cualquier output inesperado antes de enviar JSON
            ob_clean();

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
