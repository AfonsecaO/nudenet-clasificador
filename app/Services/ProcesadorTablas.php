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
     * Obtiene el siguiente registro de una tabla (WHERE id > ultimo_id ORDER BY id ASC LIMIT 1).
     * ultimo_id en estado = último ID procesado.
     */
    public function obtenerSiguienteRegistro($tabla)
    {
        try {
            $this->estadoTracker->inicializarTabla($tabla);
            $maxIdOrigen = $this->obtenerMaxIdDesdeOrigen($tabla);
            if ($maxIdOrigen !== null) {
                $this->estadoTracker->actualizarMaxId($tabla, $maxIdOrigen);
            }
            $ultimoId = $this->estadoTracker->getUltimoId($tabla);

            $columnasStr = implode(', ', array_map(function($col) {
                return "`{$col}`";
            }, $this->columnas));

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
                $idActual = (int)$registro[$this->primaryKey];
                $this->estadoTracker->actualizarUltimoId($tabla, $idActual, null);
                return [
                    'success' => true,
                    'registro' => $registro,
                    'faltan_registros' => $this->estadoTracker->faltanRegistros($tabla)
                ];
            }
            $maxIdActual = $this->obtenerMaxIdDesdeOrigen($tabla);
            if ($maxIdActual !== null) {
                $this->estadoTracker->actualizarMaxId($tabla, $maxIdActual);
            }
            $estado = $this->estadoTracker->getEstado();
            $maxId = (int)($estado[$tabla]['max_id'] ?? 0);
            $faltan = ($maxId > $ultimoId);
            $idParaCompletar = ($maxId > 0) ? $maxId : $ultimoId;
            $this->estadoTracker->actualizarUltimoId($tabla, $idParaCompletar, $faltan);
            return [
                'success' => true,
                'registro' => null,
                'faltan_registros' => $faltan
            ];
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
     * Consulta el MAX(id) actual en la BD origen para una tabla.
     * Devuelve null si no se puede obtener (tabla no existe o error).
     */
    private function obtenerMaxIdDesdeOrigen(string $tabla): ?int
    {
        if (!$this->database->tablaExiste($tabla)) {
            return null;
        }
        try {
            $conn = $this->database->getConnection();
            $sql = "SELECT MAX(`{$this->primaryKey}`) as max_id FROM `{$tabla}`";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row && isset($row['max_id']) && $row['max_id'] !== null) {
                return (int) $row['max_id'];
            }
            return 0;
        } catch (\Throwable $e) {
            return null;
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
