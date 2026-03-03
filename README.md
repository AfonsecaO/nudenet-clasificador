# PhotoClassifier (Clasificador)

Aplicación PHP para clasificar y gestionar fotos por **workspace**, con soporte SQLite o MySQL y sincronización opcional con una base de datos externa.

## Descripción del proyecto

PhotoClassifier permite:

- Elegir motor de almacenamiento (SQLite o MySQL) y configurar workspaces.
- Trabajar en modo "solo imágenes" o "DB + imágenes" (sincronización con BD externa).
- Buscar carpetas y registros, visualizar imágenes y avatares, generar thumbs en caché.
- Reindexar imágenes desde el filesystem, subir imágenes y procesar tablas (descarga/sincronización).
- Mantener logs por workspace en `workspaces/<slug>/logs/`.

## Arquitectura

```
[HTTP] → public/index.php → Router::dispatch()
    → gating (storage_engine, workspace, setup)
    → Controller → Service / Model → BD o filesystem
```

- **Capa de presentación**: `app/Controllers/`, `app/Views/` (PHP con layout).
- **Lógica de negocio y datos**: `app/Models/` (CarpetasIndex, ImagenesIndex, EstadoTracker), `app/Services/` (ConfigService, WorkspaceService, AppConnection, AppSchema, LogService, etc.).
- **Configuración**: `config/routes.php`, `config/mysql_app.php`, `database/storage_engine.json`; opcionalmente `.env` (véase Configuración).

## Requisitos

- **PHP** >= 7.4
- Extensiones: `pdo_sqlite`, `pdo_mysql` (si se usa MySQL), `json`, `gd` o `imagick` (para thumbs y compresión)
- **MySQL/MariaDB** opcional (para motor de BD o para BD externa en modo DB+imágenes)

## Instalación

```bash
git clone <repo> clasificador
cd clasificador
composer install
```

1. Copiar la plantilla de entorno:  
   `cp config/env.example .env`  
   y ajustar variables si es necesario.

2. Apuntar el document root del servidor web a la carpeta **`public/`** (no a la raíz del proyecto).

3. Si no existe `database/storage_engine.json`, la aplicación mostrará el asistente para elegir motor de BD (SQLite/MySQL) y, si aplica, la pantalla de parametrización global de MySQL.

## Configuración

### Variables de entorno (`.env`)

| Variable | Descripción |
|--------|-------------|
| `APP_ENV` | `dev` o `prod`. En `prod` no se exponen mensajes de excepción al cliente y se registran en `tmp/logs/app.log`. |
| `MYSQL_APP_HOST` | Host MySQL (si no se usa `config/mysql_app.json`). |
| `MYSQL_APP_PORT` | Puerto MySQL (por defecto 3306). |
| `MYSQL_APP_USER` | Usuario MySQL. |
| `MYSQL_APP_PASS` | Contraseña MySQL. |
| `MYSQL_APP_DATABASE` | Base de datos (por defecto `clasificador`). |

### Archivos

- **`config/routes.php`**: Definición de rutas (action → controller + method).
- **`config/mysql_app.json`**: Credenciales MySQL (generado desde la UI o manualmente; recomendado no versionar en producción y usar solo variables de entorno).
- **`database/storage_engine.json`**: Motor de BD (`sqlite` o `mysql`) y opciones (ej. `registros_descarga`).

La configuración por workspace (conexión a BD externa, patrón de tablas, columnas, directorio de imágenes) se guarda en la tabla `app_config` de la BD interna.

## Estructura del proyecto

```
app/
  Controllers/    # Controladores HTTP (API y vistas)
  Models/         # CarpetasIndex, ImagenesIndex, EstadoTracker, etc.
  Services/       # Router, AppConnection, ConfigService, WorkspaceService, LogService, AppLogger, etc.
  Views/          # Vistas PHP y layout
config/
  routes.php
  env.example
  mysql_app.php   # Carga MysqlAppConfig
database/
  storage_engine.json
  clasificador.sqlite   # Si motor = sqlite
public/           # Document root
  index.php
  css/
  js/
tmp/
  logs/            # app.log (errores de aplicación), dashboard.log por workspace
workspaces/        # Datos por workspace (images, logs, cache, thumbs, avatars)
```

## Endpoints (rutas)

Las rutas se invocan con `?action=<ruta>` o con el primer segmento del path según la configuración del servidor.

| Action | Tipo | Descripción |
|--------|------|-------------|
| `storage_engine_select` | view | Pantalla para elegir motor de BD. |
| `storage_engine_save` | api | Guardar motor (sqlite/mysql). |
| `workspace_select` | view | Selector de workspace. |
| `workspace_create` | api | Crear workspace (POST). |
| `workspace_set` | api | Establecer workspace actual. |
| `workspace_delete` | api | Eliminar workspace. |
| `workspace_global_config` | view | Parametrización global MySQL. |
| `workspace_global_config_save` | api | Guardar config global MySQL. |
| `buscar_carpetas_global` | api | Búsqueda consolidada de carpetas. |
| `obtener_tablas` | api | Obtener tablas según patrón configurado. |
| `procesar` | api | Procesar tablas (descarga/sincronización). |
| `estadisticas_descarga` | api | Estadísticas de descarga. |
| `reindex_imagenes` | api | Reindexar imágenes desde el filesystem. |
| `buscar_carpetas` | api | Buscar carpetas (GET `q`). |
| `ver_carpeta` | api | Contenido de carpeta (GET `ruta`, `workspace`). |
| `ver_imagen` | api | Servir imagen o thumb (GET `ruta`, `archivo`, `thumb`, `w`). |
| `ver_avatar` | api | Servir avatar (GET `k`, `workspace`). |
| `upload_imagenes` | api | Subir imágenes (POST `paths`). |
| `log_append` | api | Añadir entrada al log del dashboard. |
| `log_tail` | api | Últimas N entradas (GET `limit`). |
| `log_clear` | api | Limpiar log del dashboard. |
| `setup` | view | Pantalla de configuración del workspace. |
| `setup_test_db` | api | Probar conexión a BD. |
| `setup_test_schema` | api | Probar esquema y columnas. |
| `setup_test_dir` | api | Probar directorio de imágenes. |
| `setup_save` | api | Guardar configuración (payload JSON). |
| `setup_save_section` | api | Guardar sección de configuración. |
| `index` | view | Inicio (redirige a workspace_select si no hay acción). |

## Estrategia de despliegue

- **Document root**: debe ser la carpeta `public/` (evitar exponer `app/`, `config/`, `.env`).
- **Producción**:
  - Definir `APP_ENV=prod` en el servidor o en `.env`.
  - Usar HTTPS.
  - No versionar `config/mysql_app.json` ni `.env`; usar variables de entorno para credenciales.
  - Restringir acceso a rutas sensibles (setup, workspace_global_config_save, workspace_delete, etc.) mediante red privada, VPN o autenticación (la aplicación no incluye auth integrada; se recomienda proxy o auth en el servidor).

## Recomendaciones para producción

- Colocar la aplicación en red restringida o detrás de autenticación (proxy, HTTP auth o aplicación externa).
- Mantener rotación de logs (`tmp/logs/app.log`, logs por workspace).
- Realizar backups periódicos de la base de datos (SQLite: `database/clasificador.sqlite`; MySQL: dump) y de la carpeta `workspaces/` si contiene datos críticos.
- Revisar permisos de escritura en `database/`, `tmp/`, `workspaces/`.

## Roadmap futuro

- Autenticación integrada.
- Cola de trabajos para reindexación pesada.
- API REST versionada y documentada (OpenAPI).
- Posible desacoplamiento de workers de procesamiento (microservicios o jobs en background).

## Licencia

Según el proyecto.
