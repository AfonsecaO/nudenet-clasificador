<?php

namespace App\Services;

/**
 * Servicio de Enrutamiento
 * 
 * Maneja el enrutamiento de peticiones HTTP a los controladores correspondientes.
 */
class Router
{
    private static $routes = null;

    private static function isWorkspaceRoute(string $route): bool
    {
        return strpos($route, 'workspace_') === 0;
    }

    /** Rutas que agregan datos de todos los workspaces y no requieren workspace actual */
    private static function isGlobalConsolidatedRoute(string $route): bool
    {
        return in_array($route, [
            'buscar_carpetas_global',
            'etiquetas_detectadas_global',
            'buscar_imagenes_etiquetas_global',
        ], true);
    }

    private static function needsWorkspaceResponse(): void
    {
        header('Content-Type: application/json');
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'needs_workspace' => true,
        ]);
        exit;
    }
    
    /**
     * Carga las rutas desde el archivo de configuración
     */
    private static function loadRoutes()
    {
        if (self::$routes === null) {
            $routesFile = __DIR__ . '/../../config/routes.php';
            if (!file_exists($routesFile)) {
                throw new \Exception("Archivo de rutas no encontrado: {$routesFile}");
            }
            self::$routes = require $routesFile;
        }
        return self::$routes;
    }
    
    /**
     * Obtiene la ruta actual desde la petición HTTP
     */
    private static function getCurrentRoute()
    {
        // Intentar obtener desde query string primero
        if (isset($_GET['action']) && !empty($_GET['action'])) {
            return $_GET['action'];
        }
        
        // Intentar obtener desde el path
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($requestUri, PHP_URL_PATH);
        $path = ltrim($path, '/');
        
        // Si el path está vacío o es 'index.php', retornar 'index'
        if (empty($path) || $path === 'index.php') {
            return 'index';
        }
        
        // Extraer la primera parte del path como ruta
        $pathParts = explode('/', $path);
        return $pathParts[0];
    }
    
    /**
     * Resuelve y ejecuta la ruta
     */
    public static function dispatch()
    {
        try {
            $routes = self::loadRoutes();
            $route = self::getCurrentRoute();

            // Landing rule: al entrar por "/" (sin ?action=...), siempre mostrar selector de workspace.
            // Esto permite que el usuario elija explícitamente el workspace en cada entrada a la app.
            $isLanding = !isset($_GET['action']) || trim((string)($_GET['action'] ?? '')) === '';
            if ($isLanding && (string)$route === 'index') {
                $route = 'workspace_select';
            }

            // Workspace por request: permite abrir varias pestañas con distintos workspaces (workers en paralelo).
            if (isset($_REQUEST['workspace']) && is_string($_REQUEST['workspace'])) {
                $slug = \App\Services\WorkspaceService::slugify(trim($_REQUEST['workspace']));
                if ($slug !== '' && \App\Services\WorkspaceService::exists($slug)) {
                    \App\Services\WorkspaceService::setRequestOverride($slug);
                }
            }

            // Workspace gating: antes de tocar SQLite
            $ws = \App\Services\WorkspaceService::current();
            if ($ws === null && !self::isWorkspaceRoute((string)$route) && !self::isGlobalConsolidatedRoute((string)$route)) {
                // Para API: responder 409; para vistas: forzar pantalla de workspace
                $routeConfigTmp = $routes[$route] ?? null;
                $typeTmp = is_array($routeConfigTmp) ? ($routeConfigTmp['type'] ?? 'api') : 'api';
                if ($typeTmp === 'api') {
                    self::needsWorkspaceResponse();
                }
                $route = 'workspace_select';
            }

            // Si es ruta workspace, no requiere ws existente (create/select)
            // pero si ya hay ws en cookie, asegurar estructura
            if ($ws !== null) {
                \App\Services\WorkspaceService::ensureStructure($ws);
            }

            // Si estamos en rutas de workspace (select/create/set), no abrir SQLite ni aplicar setup gating.
            if (self::isWorkspaceRoute((string)$route)) {
                $routes = self::loadRoutes();
                if (!isset($routes[$route])) {
                    self::handleNotFound();
                    return;
                }
                $routeConfig = $routes[$route];
                $controllerName = $routeConfig['controller'];
                $method = $routeConfig['method'];

                $controllerClass = "App\\Controllers\\{$controllerName}";
                if (!class_exists($controllerClass)) {
                    throw new \Exception("Controlador no encontrado: {$controllerClass}");
                }
                $controller = new $controllerClass();
                if (!method_exists($controller, $method)) {
                    throw new \Exception("Método no encontrado en {$controllerClass}: {$method}");
                }
                $controller->$method();
                return;
            }

            // Rutas globales (consolidado de todos los workspaces): no requieren workspace ni abrir su SQLite
            if (self::isGlobalConsolidatedRoute((string)$route)) {
                $routes = self::loadRoutes();
                if (!isset($routes[$route])) {
                    self::handleNotFound();
                    return;
                }
                $routeConfig = $routes[$route];
                $controllerName = $routeConfig['controller'];
                $method = $routeConfig['method'];

                $controllerClass = "App\\Controllers\\{$controllerName}";
                if (!class_exists($controllerClass)) {
                    throw new \Exception("Controlador no encontrado: {$controllerClass}");
                }
                $controller = new $controllerClass();
                if (!method_exists($controller, $method)) {
                    throw new \Exception("Método no encontrado en {$controllerClass}: {$method}");
                }
                $controller->$method();
                return;
            }

            // Asegurar SQLite + esquema (y migración legacy si aplica) - ya con workspace resuelto
            $pdo = SqliteConnection::get();
            SqliteSchema::ensure($pdo);
            SqliteMigrator::bootstrap();

            // Verificar si la ruta existe
            if (!isset($routes[$route])) {
                self::handleNotFound();
                return;
            }
            
            $routeConfig = $routes[$route];
            $controllerName = $routeConfig['controller'];
            $method = $routeConfig['method'];
            $type = $routeConfig['type'] ?? 'api';

            // Gating: si falta configuración, forzar pantalla de setup o error 409
            $esRutaSetup = (strpos((string)$route, 'setup') === 0);
            $esRutaWorkspace = self::isWorkspaceRoute((string)$route);
            if (!$esRutaWorkspace && !$esRutaSetup && !\App\Services\ConfigService::estaConfigurado()) {
                $faltantes = \App\Services\ConfigService::faltantesRequeridos();
                if ($type === 'api') {
                    header('Content-Type: application/json');
                    http_response_code(409);
                    echo json_encode([
                        'success' => false,
                        'needs_setup' => true,
                        'faltantes' => $faltantes
                    ]);
                    exit;
                }

                // Forzar vista de setup
                $controllerName = 'SetupController';
                $method = 'index';
            }
            
            // Construir el nombre completo de la clase del controlador
            $controllerClass = "App\\Controllers\\{$controllerName}";
            
            // Verificar que la clase existe
            if (!class_exists($controllerClass)) {
                throw new \Exception("Controlador no encontrado: {$controllerClass}");
            }
            
            // Instanciar el controlador
            $controller = new $controllerClass();
            
            // Verificar que el método existe
            if (!method_exists($controller, $method)) {
                throw new \Exception("Método no encontrado en {$controllerClass}: {$method}");
            }
            
            // Ejecutar el método del controlador
            $controller->$method();
            
        } catch (\Exception $e) {
            self::handleError($e);
        }
    }
    
    /**
     * Maneja errores 404 (Ruta no encontrada)
     */
    private static function handleNotFound()
    {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Ruta no encontrada'
        ]);
        exit;
    }
    
    /**
     * Maneja errores generales
     */
    private static function handleError(\Exception $e)
    {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
        exit;
    }
    
    /**
     * Obtiene la URL base de la aplicación
     */
    public static function getBaseUrl()
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        
        return "{$protocol}://{$host}{$script}";
    }
    
    /**
     * Genera una URL para una ruta específica
     */
    public static function url($route, $params = [])
    {
        $baseUrl = self::getBaseUrl();
        $url = rtrim($baseUrl, '/') . '/?action=' . urlencode($route);
        
        if (!empty($params)) {
            $url .= '&' . http_build_query($params);
        }
        
        return $url;
    }
}
