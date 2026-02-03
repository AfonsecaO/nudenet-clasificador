<?php

/**
 * Punto de Entrada Principal
 * 
 * Este archivo es el único punto de entrada público de la aplicación.
 * Todas las peticiones HTTP pasan por aquí y son enrutadas por el Router.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\Router;

// Enrutar la petición
Router::dispatch();
