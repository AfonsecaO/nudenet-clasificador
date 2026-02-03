<?php

namespace App\Models;

class TablaBuscador
{
    private $database;
    private $pattern;

    public function __construct()
    {
        $this->database = new Database();
        $this->loadPattern();
    }

    private function loadPattern()
    {
        $mode = \App\Services\ConfigService::getWorkspaceMode();
        if ($mode !== 'db_and_images') {
            $this->pattern = '';
            return;
        }
        $this->pattern = (string)\App\Services\ConfigService::obtenerRequerido('TABLE_PATTERN');
    }

    public function buscarTablas()
    {
        try {
            if (trim((string)$this->pattern) === '') {
                return [
                    'success' => true,
                    'pattern' => $this->pattern,
                    'tablas' => [],
                    'total' => 0
                ];
            }
            $tablas = $this->database->buscarTablasPorPatron($this->pattern);
            return [
                'success' => true,
                'pattern' => $this->pattern,
                'tablas' => $tablas,
                'total' => count($tablas)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'pattern' => $this->pattern,
                'tablas' => [],
                'total' => 0
            ];
        }
    }

    public function getPattern()
    {
        return $this->pattern;
    }
}
