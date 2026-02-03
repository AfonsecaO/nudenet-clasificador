<?php

namespace App\Controllers;

class BaseController
{
    public function __construct()
    {
        // La validación de configuración se maneja en Router/setup gating.
    }
    
    protected function jsonResponse($data, $statusCode = 200)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
    
    /**
     * Renderiza una vista
     */
    protected function render($view, $data = [])
    {
        // Extraer variables del array $data para que estén disponibles en la vista
        extract($data);
        
        // Incluir la vista
        $viewPath = __DIR__ . '/../Views/' . str_replace('.', '/', $view) . '.php';
        
        if (!file_exists($viewPath)) {
            throw new \Exception("Vista no encontrada: {$viewPath}");
        }

        // Soporte de layout: la vista puede poblar $__head / $__title / $__bodyClass
        $__title = $data['title'] ?? ($__title ?? null) ?? 'Clasificador';
        $__head = $data['head'] ?? ($__head ?? '');
        $__bodyClass = $data['bodyClass'] ?? ($__bodyClass ?? '');

        ob_start();
        require $viewPath;
        $__content = ob_get_clean();

        $layoutPath = __DIR__ . '/../Views/layout.php';
        if (file_exists($layoutPath)) {
            require $layoutPath;
        } else {
            echo $__content;
        }
        exit;
    }
}
