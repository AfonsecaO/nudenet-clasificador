<?php

namespace App\Services;

use App\Models\Database;
use App\Models\EstadoTracker;
use PDOException;

class ProcesadorTablas
{
    private $database;
    private $estadoTracker;
    private $primaryKey;
    private $columnas;
    private $campoIdentificador;
    private $campoUsrId;
    private $campoFecha;

    public function __construct()
    {
        $this->database = new Database();
        $this->estadoTracker = new EstadoTracker();
        $this->cargarConfiguracion();
    }

    private function cargarConfiguracion()
    {
        // Primary key configurable
        $this->primaryKey = \App\Services\ConfigService::obtenerRequerido('PRIMARY_KEY');
        
        // Campo identificador
        $this->campoIdentificador = \App\Services\ConfigService::obtenerRequerido('CAMPO_IDENTIFICADOR');
        
        // Campo usr_id
        $this->campoUsrId = \App\Services\ConfigService::obtenerRequerido('CAMPO_USR_ID');
        
        // Campo de fecha
        $this->campoFecha = \App\Services\ConfigService::obtenerRequerido('CAMPO_FECHA');
        
        // Construir columnas automáticamente a partir de los campos configurados
        // No es necesario especificar COLUMNAS, se construye automáticamente
        $this->columnas = [];
        
        // 1. Primary key (siempre primero)
        $this->columnas[] = $this->primaryKey;
        
        // 2. Campo identificador
        $this->columnas[] = $this->campoIdentificador;
        
        // 3. Campo usr_id
        $this->columnas[] = $this->campoUsrId;
        
        // 4. Campo fecha
        $this->columnas[] = $this->campoFecha;
        
        // 5. Campo resultado (opcional)
        $campoResultado = \App\Services\ConfigService::obtenerOpcional('CAMPO_RESULTADO');
        if ($campoResultado) {
            $this->columnas[] = $campoResultado;
        }
        
        // 6. Columnas de imagen (obtener desde COLUMNAS_IMAGEN - requerido)
        $columnasImagenStr = \App\Services\ConfigService::obtenerRequerido('COLUMNAS_IMAGEN');
        $columnasImagen = array_map('trim', explode(',', $columnasImagenStr));
        foreach ($columnasImagen as $columnaImg) {
            if (!empty($columnaImg) && !in_array($columnaImg, $this->columnas)) {
                $this->columnas[] = $columnaImg;
            }
        }
        
        // Eliminar duplicados manteniendo el orden
        $this->columnas = array_values(array_unique($this->columnas));
    }

    /**
     * Obtiene el siguiente registro de una tabla
     */
    public function obtenerSiguienteRegistro($tabla)
    {
        try {
            // Inicializar tabla en el estado si no existe
            $this->estadoTracker->inicializarTabla($tabla);
            
            // Obtener último ID procesado
            $ultimoId = $this->estadoTracker->getUltimoId($tabla);
            
            // Construir lista de columnas para la query
            $columnasStr = implode(', ', array_map(function($col) {
                return "`{$col}`";
            }, $this->columnas));
            
            // Query para obtener el siguiente registro
            $sql = "SELECT {$columnasStr} 
                    FROM `{$tabla}` 
                    WHERE `{$this->primaryKey}` > :ultimo_id 
                    ORDER BY `{$this->primaryKey}` ASC 
                    LIMIT 1";
            
            $conn = $this->database->getConnection();
            $stmt = $conn->prepare($sql);
            $stmt->execute([':ultimo_id' => $ultimoId]);
            
            $registro = $stmt->fetch();
            
            if ($registro) {
                // Obtener max_id del estado si existe
                $estadoTabla = $this->estadoTracker->getEstado()[$tabla] ?? null;
                $maxId = $estadoTabla['max_id'] ?? null;
                $idActual = $registro[$this->primaryKey];
                
                // Si tenemos el max_id en el estado, usarlo para comparar
                if ($maxId !== null) {
                    $faltanRegistros = ($idActual < $maxId);
                } else {
                    // Si no tenemos el max_id, obtenerlo de la BD
                    $sqlMax = "SELECT MAX(`{$this->primaryKey}`) as max_id FROM `{$tabla}`";
                    $stmtMax = $conn->prepare($sqlMax);
                    $stmtMax->execute();
                    $resultadoMax = $stmtMax->fetch();
                    $maxIdBd = $resultadoMax['max_id'] !== null ? (int)$resultadoMax['max_id'] : 0;
                    
                    // Guardar el max_id en el estado
                    if ($maxIdBd > 0) {
                        $this->estadoTracker->actualizarMaxId($tabla, $maxIdBd);
                    }
                    
                    $faltanRegistros = ($idActual < $maxIdBd);
                }
                
                // Actualizar estado
                $this->estadoTracker->actualizarUltimoId(
                    $tabla, 
                    $idActual, 
                    $faltanRegistros
                );
                
                return [
                    'success' => true,
                    'registro' => $registro,
                    'faltan_registros' => $faltanRegistros
                ];
            } else {
                // No hay más registros
                $this->estadoTracker->actualizarUltimoId($tabla, $ultimoId, false);
                
                return [
                    'success' => true,
                    'registro' => null,
                    'faltan_registros' => false
                ];
            }
        } catch (PDOException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'registro' => null,
                'faltan_registros' => false
            ];
        }
    }

    /**
     * Obtiene las columnas de imagen configuradas
     */
    public function getColumnasImagen()
    {
        $columnasImagenStr = \App\Services\ConfigService::obtenerRequerido('COLUMNAS_IMAGEN');
        return array_filter(array_map('trim', explode(',', $columnasImagenStr)));
    }

    public function getCampoIdentificador()
    {
        return $this->campoIdentificador;
    }

    public function getCampoUsrId()
    {
        return $this->campoUsrId;
    }

    public function getCampoFecha()
    {
        return $this->campoFecha;
    }
    
    public function getCampoResultado()
    {
        return \App\Services\ConfigService::obtenerOpcional('CAMPO_RESULTADO');
    }
}
