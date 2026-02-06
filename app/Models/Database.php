<?php

namespace App\Models;

use PDO;
use PDOException;

class Database
{
    private $connection;
    private $host;
    private $port;
    private $dbName;
    private $user;
    private $pass;

    public function __construct()
    {
        $this->loadEnv();
        $this->connect();
    }

    private function loadEnv()
    {
        \App\Services\ConfigService::cargarYValidar();

        $this->host = \App\Services\ConfigService::obtenerRequerido('DB_HOST');
        $this->port = (int)\App\Services\ConfigService::obtenerRequerido('DB_PORT');
        $this->dbName = \App\Services\ConfigService::obtenerRequerido('DB_NAME');
        $this->user = \App\Services\ConfigService::obtenerRequerido('DB_USER');
        $this->pass = \App\Services\ConfigService::obtenerRequerido('DB_PASS');
    }

    private function connect()
    {
        try {
            $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->dbName};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            $this->connection = new PDO($dsn, $this->user, $this->pass, $options);
        } catch (PDOException $e) {
            throw new \Exception("Error de conexión a la base de datos: " . $e->getMessage());
        }
    }

    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Busca tablas que coincidan con el patrón.
     * Comodines: ? = un carácter, * = cero o más caracteres.
     * Sin comodines = coincidencia exacta (ej: "ia_miner" solo esa tabla).
     *
     * @param string $pattern Patrón (ej: "ia_miner", "ia_miner?", "ia_miner*", "ia_*_facial")
     * @return array Lista de nombres de tablas encontradas
     */
    public function buscarTablasPorPatron($pattern)
    {
        $pattern = trim((string) $pattern);
        $hasWildcards = (strpos($pattern, '?') !== false || strpos($pattern, '*') !== false);

        if (!$hasWildcards) {
            $sql = "SELECT TABLE_NAME 
                    FROM INFORMATION_SCHEMA.TABLES 
                    WHERE TABLE_SCHEMA = :db_name 
                    AND TABLE_NAME = :pattern
                    ORDER BY TABLE_NAME ASC";
            $params = [':db_name' => $this->dbName, ':pattern' => $pattern];
        } else {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $pattern);
            $likePattern = str_replace(['*', '?'], ['%', '_'], $escaped);
            $sql = "SELECT TABLE_NAME 
                    FROM INFORMATION_SCHEMA.TABLES 
                    WHERE TABLE_SCHEMA = :db_name 
                    AND TABLE_NAME LIKE :pattern
                    ORDER BY TABLE_NAME ASC";
            $params = [':db_name' => $this->dbName, ':pattern' => $likePattern];
        }

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            $tablas = [];
            while ($row = $stmt->fetch()) {
                $tablas[] = $row['TABLE_NAME'];
            }
            return $tablas;
        } catch (PDOException $e) {
            throw new \Exception("Error al buscar tablas: " . $e->getMessage());
        }
    }

    /**
     * Verifica si una tabla existe en la base de datos
     */
    public function tablaExiste($tabla)
    {
        try {
            $sql = "SELECT COUNT(*) as total 
                    FROM INFORMATION_SCHEMA.TABLES 
                    WHERE TABLE_SCHEMA = :db_name 
                    AND TABLE_NAME = :tabla";
            
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([
                ':db_name' => $this->dbName,
                ':tabla' => $tabla
            ]);
            
            $resultado = $stmt->fetch();
            return $resultado['total'] > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
}
