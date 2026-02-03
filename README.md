# Clasificador

Aplicación PHP (sin framework) para **clasificación de imágenes mediante IA** con soporte para:

- **Workspaces aislados**: múltiples ambientes (producción, pruebas, personal) con datos independientes
- **Dos modos de operación**:
  - **DB + imágenes**: descubre tablas en MySQL/MariaDB por patrón, materializa imágenes a disco y las clasifica
  - **Solo imágenes**: trabaja con carpetas locales (subida o importación), sin base de datos
- **Índice SQLite** por workspace para búsqueda y reanudación
- **Servicio HTTP externo** para detección/clasificación (p.ej. NudeNet u otro compatible)

---

## Tabla de contenidos

- [Requisitos](#requisitos)
- [Instalación](#instalación)
- [Ejecución](#ejecución)
- [Workspaces](#workspaces)
- [Configuración](#configuración)
- [Servicio externo (clasificador HTTP)](#servicio-externo-clasificador-http)
- [Flujo de trabajo](#flujo-de-trabajo)
- [Endpoints](#endpoints)
- [Persistencia y archivos](#persistencia-y-archivos)
- [Reset](#reset)
- [Estructura del proyecto](#estructura-del-proyecto)

---

## Requisitos

- **PHP >= 7.4** y **Composer**
- **MySQL/MariaDB** (solo si usas modo *DB + imágenes*)

### Extensiones PHP recomendadas

| Extensión  | Uso                                       |
|-----------|-------------------------------------------|
| `pdo_mysql` | Conexión a MySQL (modo DB)                |
| `pdo_sqlite` / `sqlite3` | Índice SQLite por workspace   |
| `curl`     | Llamadas al clasificador externo          |
| `fileinfo` | MIME de imágenes al servir/validar        |
| `json`     | Respuestas JSON                           |

Opcionales (para HEIC/HEIF):

- **Imagick** con soporte HEIC, o
- **ImageMagick** (`convert`/`magick`) o **heif-convert** (libheif)

---

## Instalación

```bash
composer install
```

---

## Ejecución

### Opción 1 (Windows)

```bash
serve.bat
```

### Opción 2 (Linux/Mac)

```bash
chmod +x serve.sh
./serve.sh
```

### Opción 3 (manual)

```bash
# Desde public/
cd public
php -S localhost:8000

# O desde la raíz
php -S localhost:8000 -t public
```

Luego abre **http://localhost:8000**.

---

## Workspaces

Al entrar por primera vez se muestra el **selector de workspaces**. Cada workspace es un ambiente aislado con:

- Base de datos SQLite propia
- Directorio de imágenes
- Logs y caché (miniaturas, avatares)

### Estructura de un workspace

```
workspaces/<slug>/
├── db/
│   └── clasificador.sqlite
├── images/
├── logs/
└── cache/
    ├── thumbs/
    └── avatars/
```

### Acciones

- **Crear**: nombre → slug (`[a-z0-9\-_]`)
- **Entrar**: selecciona el workspace activo (cookie)
- **Eliminar**: requiere confirmar escribiendo el nombre exacto

Las rutas de la app requieren un workspace activo; si no hay, las APIs responden **HTTP 409** con `needs_workspace: true` y las vistas fuerzan el selector.

---

## Configuración

La configuración se guarda en **SQLite** (tabla `app_config`) dentro del workspace actual. No usa `.env`.

1. Entra en el workspace que quieras configurar
2. Abre `/?action=setup`
3. Prueba cada sección (DB, Schema, Clasificador)
4. Guarda la configuración

Si falta configuración, el sistema fuerza la pantalla de setup y las APIs responden **HTTP 409** con `needs_setup: true`.

### Modo de workspace (requerido)

- **`images_only`**: solo imágenes locales (subida o importación). No requiere DB.
- **`db_and_images`**: materializa imágenes desde MySQL/MariaDB y las clasifica. Requiere configuración DB + schema.

### Parámetros requeridos

| Parámetro            | Modo               | Descripción                                      |
|----------------------|--------------------|--------------------------------------------------|
| `WORKSPACE_MODE`     | Ambos              | `images_only` o `db_and_images`                  |
| `CLASIFICADOR_BASE_URL` | Ambos           | URL base del servicio de detección (p.ej. `http://localhost:8001`) |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | `db_and_images` | Conexión MySQL |
| `TABLE_PATTERN`      | `db_and_images`    | Patrón de tablas (`?` = 1 carácter → LIKE con `_`) |
| `PRIMARY_KEY`        | `db_and_images`    | Columna id para paginación (MAX)                 |
| `CAMPO_IDENTIFICADOR`, `CAMPO_USR_ID`, `CAMPO_FECHA` | `db_and_images` | Campos para nombrar archivos |
| `COLUMNAS_IMAGEN`    | `db_and_images`    | Columnas con imágenes (CSV)                      |

### Parámetros opcionales

| Parámetro                 | Descripción                                                                 |
|---------------------------|-----------------------------------------------------------------------------|
| `CAMPO_RESULTADO`         | Campo para el patrón de materialización (si existe en BD)                   |
| `PATRON_MATERIALIZACION`  | Patrón de archivos. Default: `{{CAMPO_IDENTIFICADOR}}/{{CAMPO_IDENTIFICADOR}}_{{CAMPO_USR_ID}}_{{CAMPO_RESULTADO}}_{{CAMPO_FECHA}}.ext` |
| `DETECT_IGNORED_LABELS`   | CSV de labels a ignorar. Si una imagen solo tiene labels ignorados → `safe` |

En modo `db_and_images`, las imágenes se guardan en `workspaces/<ws>/images/` según el patrón.

---

## Servicio externo (clasificador HTTP)

La app llama a un servicio externo para **detección/clasificación** de imágenes.

### Health check

- **GET** `${CLASIFICADOR_BASE_URL}/health`
- Se espera HTTP 2xx y JSON (ideal: `{"ok": true}`)

### Detección

- **POST** `${CLASIFICADOR_BASE_URL}/detect` (multipart `file`)
- Formatos de respuesta aceptados:
  - `[]` (lista vacía o con items)
  - `{"parts": [...]}` o `{"detections": [...]}`
  - `{"success": true, "prediction": [...]}` (tipo NudeNet)

Cada item puede tener:

- `{"label":"...", "score": <number>, "box":[x1,y1,x2,y2]}`
- o `{"class":"...", "score": <number>, "box":[x1,y1,x2,y2]}`

### Decisión safe/unsafe

1. Se normalizan los `label` a **MAYÚSCULAS**
2. Se eliminan detecciones cuyo `label` esté en `DETECT_IGNORED_LABELS`
3. **unsafe**: existe al menos una detección no ignorada
4. **safe**: no existe ninguna detección no ignorada

Las detecciones no ignoradas se guardan en SQLite (`detections`) con `score` y `box` si vienen.

### Labels conocidos (NudeNet)

La app incluye un diccionario de labels oficiales (p.ej. `FACE_FEMALE`, `FEMALE_BREAST_EXPOSED`, etc.) para la UI y búsqueda.

---

## Flujo de trabajo

### Modo DB + imágenes

1. **Descubrir tablas**: se buscan tablas por `TABLE_PATTERN`
2. **Materializar**: `/?action=procesar` — toma 1 registro de la primera tabla pendiente, escribe imágenes a disco y actualiza el índice SQLite
3. **Clasificar**: `/?action=procesar_imagenes` — procesa 1 imagen pendiente por llamada: envía a `/detect` y guarda detecciones

### Modo solo imágenes

1. **Subir carpeta**: arrastrar o elegir carpeta con imágenes (se respeta estructura recursiva)
2. **Reindexar** (si hace falta): para detectar nuevas imágenes en disco
3. **Clasificar**: igual que en modo DB

### Soporte HEIC/HEIF

Las imágenes HEIC/HEIF se convierten a JPG para compatibilidad (Imagick, ImageMagick o heif-convert).

---

## Endpoints

Rutas por `?action=...` o path (p.ej. `/procesar`).

### Workspaces

| Action            | Tipo  | Descripción                          |
|-------------------|-------|--------------------------------------|
| `workspace_select`| Vista | Selector de workspaces               |
| `workspace_create`| API   | Crear workspace (POST `name`)        |
| `workspace_set`   | API   | Seleccionar workspace (POST `workspace`) |
| `workspace_delete`| API   | Eliminar workspace (POST `workspace`, `confirm`) |

### Setup

| Action                  | Tipo  | Descripción                    |
|-------------------------|-------|--------------------------------|
| `setup`                 | Vista | Pantalla de parametrización    |
| `setup_test_db`         | API   | Probar conexión MySQL          |
| `setup_test_schema`     | API   | Probar schema y columnas       |
| `setup_test_dir`        | API   | Probar directorio (legacy)     |
| `setup_test_clasificador` | API | Probar servicio clasificador   |
| `setup_save`            | API   | Guardar configuración          |
| `setup_save_section`    | API   | Guardar una sección            |

### Procesamiento

| Action                     | Tipo  | Descripción                            |
|----------------------------|-------|----------------------------------------|
| `procesar`                 | API   | Procesar 1 registro (materializar)     |
| `procesar_imagenes`        | API   | Procesar 1 imagen (clasificación)      |
| `estadisticas_clasificacion` | API | Estadísticas del índice                |
| `obtener_tablas`           | API   | Obtener tablas (legacy/debug)          |

### Búsqueda e índice

| Action                     | Tipo  | Descripción                                |
|----------------------------|-------|--------------------------------------------|
| `etiquetas_detectadas`     | API   | Lista de etiquetas detectadas              |
| `buscar_imagenes_etiquetas`| API   | Buscar por etiquetas (`labels=LABEL1,LABEL2`) |
| `buscar_carpetas`          | API   | Buscar carpetas (`q=texto`)                |
| `ver_carpeta`              | API   | Ver carpeta (`ruta=`)                      |
| `ver_imagen`               | API   | Servir imagen (`ruta=`, `archivo=`)        |
| `ver_avatar`               | API   | Servir avatar                              |
| `imagen_detecciones`       | API   | Detecciones de una imagen                  |
| `reindex_imagenes`         | API   | Reindexar desde filesystem                 |
| `reset_clasificacion`      | API   | Resetear clasificación                     |

### Subida

| Action           | Tipo  | Descripción              |
|------------------|-------|--------------------------|
| `upload_imagenes`| API   | Subir imágenes (POST)    |

### Logs

| Action     | Tipo  | Descripción                              |
|------------|-------|------------------------------------------|
| `log_append`| API  | Añadir línea JSON Lines (POST)           |
| `log_tail` | API   | Últimas N líneas (`limit=1..200`)        |
| `log_clear`| API   | Limpiar log                              |

### Vistas

| Action  | Tipo  | Descripción          |
|---------|-------|----------------------|
| `index` | Vista | Dashboard principal  |

---

## Persistencia y archivos

### SQLite (por workspace)

Archivo: `workspaces/<slug>/db/clasificador.sqlite`

Tablas principales:

- `app_config` — configuración
- `tables_state` / `tables_index` — estado de tablas (modo DB)
- `images` / `folders` — índice de imágenes y carpetas
- `detections` — detecciones por imagen

### Logs

- `workspaces/<slug>/logs/dashboard.log` — JSON Lines

### Imágenes y caché

- `workspaces/<slug>/images/` — imágenes materializadas o subidas
- `workspaces/<slug>/cache/thumbs/` — miniaturas
- `workspaces/<slug>/cache/avatars/` — avatares

Extensiones indexadas por defecto: `jpg`, `jpeg`, `png`, `gif`, `webp`, `bmp`.

---

## Reset

Para empezar en limpio en un workspace:

1. Eliminar el workspace desde la UI, o
2. Borrar manualmente `workspaces/<slug>/` (incluye DB, imágenes, logs y caché)

Reiniciar el servidor y volver a `/?action=setup` si se crea un nuevo workspace.

---

## Estructura del proyecto

```
clasificador/
├── app/
│   ├── Controllers/       # Controladores (Home, Setup, Workspace, Carpetas, etc.)
│   ├── Models/            # Database, EstadoTracker, ImagenesIndex, etc.
│   ├── Services/          # Router, ConfigService, WorkspaceService, DetectionLabels, etc.
│   └── Views/             # index, setup, workspace, layout, errors
├── config/
│   └── routes.php         # Mapa action → Controller@method
├── public/
│   ├── index.php          # Punto de entrada
│   ├── css/
│   └── img/
├── workspaces/            # Datos por workspace (gitignored)
│   └── <slug>/
│       ├── db/
│       ├── images/
│       ├── logs/
│       └── cache/
├── composer.json
├── serve.bat
├── serve.sh
└── README.md
```

---

## Licencia

Proyecto interno. Consultar con el equipo de desarrollo.
