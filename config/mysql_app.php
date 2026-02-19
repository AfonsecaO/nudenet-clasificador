<?php

/**
 * Configuración del servidor MariaDB/MySQL para el almacenamiento interno de la app.
 * Una sola base de datos; las tablas se prefijan por workspace (slug_tabla).
 *
 * La configuración se lee desde config/mysql_app.json si existe (editable en Workspaces → Parametrización global).
 * Si no existe, se usan variables de entorno: MYSQL_APP_HOST, MYSQL_APP_PORT, MYSQL_APP_USER, MYSQL_APP_PASS, MYSQL_APP_DATABASE
 */
return \App\Services\MysqlAppConfig::get();
