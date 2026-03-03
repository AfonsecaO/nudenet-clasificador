<?php

/**
 * Punto de Entrada Principal
 *
 * Este archivo es el único punto de entrada público de la aplicación.
 * Todas las peticiones HTTP pasan por aquí y son enrutadas por el Router.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
if (is_file($envFile) && is_readable($envFile)) {
    $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, " \t\"'");
                if ($key !== '') {
                    putenv($key . '=' . $value);
                    $_ENV[$key] = $value;
                }
            }
        }
    }
}

use App\Services\Router;

// Headers de seguridad (mitigan XSS, clickjacking y MIME sniffing)
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Enrutar la petición
Router::dispatch();
