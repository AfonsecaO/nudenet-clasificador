# Informe técnico — Mejoras aplicadas (PhotoClassifier)

Resumen de problemas detectados, severidad, impacto y soluciones aplicadas según el plan de mejora integral.

---

## Resumen ejecutivo

| Problema | Severidad | Impacto | Solución aplicada | Beneficio |
|----------|-----------|---------|-------------------|-----------|
| Bug SqliteMigrator: columna `nombre` en tabla `folders` | Alta | Fallo en migración/consulta de carpetas (esquema usa `name`) | Sustitución de `nombre` por `name` en la consulta de `SqliteMigrator` | Consistencia con el esquema y ejecución correcta del migrador |
| Path traversal incompleto en CarpetasController | Media | Riesgo de lectura de archivos fuera del directorio del workspace (p. ej. con symlinks) | Validación con `realpath()` y comprobación de que la ruta resuelta está bajo el directorio base en `ver`, `verImagen` y `verAvatar` | Confinamiento seguro de acceso a archivos |
| Sin headers de seguridad | Media | Mayor superficie para XSS, clickjacking y MIME sniffing | Envío de `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin` en `public/index.php` | Mitigación de vectores comunes |
| Excepción expuesta en respuestas 500 | Media | Fuga de información y detalles internos en producción | En producción (`APP_ENV=prod`), registro de la excepción en `AppLogger` y respuesta genérica "Error interno del servidor" en `Router::handleError` | Seguridad y diagnóstico sin exponer detalles al cliente |
| N+1 en sincronización de imágenes | Alta | Miles de consultas extra en reindex/upload (una SELECT por archivo para dedupe por MD5) | Precarga de todos los `content_md5` y `raw_md5` del workspace en un único set en memoria; uso de ese set en los bucles de `sincronizarDesdeDirectorio` y `upsertDesdeRutas` en lugar de `existeHashEnIndice()` por archivo | Reducción drástica de consultas y mejora de tiempo de sincronización |
| Credenciales y configuración por entorno | Media | Riesgo de credenciales en repositorio y falta de separación dev/prod | Inclusión de `config/mysql_app.json` en `.gitignore`, creación de `config/env.example`, y cargador de `.env` en `public/index.php` para `APP_ENV` y `MYSQL_APP_*` | Configuración por entorno y menor riesgo de fuga de secretos |
| Código duplicado en Router (despacho) | Baja | Mantenibilidad y posibles inconsistencias | Extracción de `Router::runController($controllerName, $method)` y uso en las tres ramas (workspace, global, resto) | DRY y un solo punto de despacho |
| Falta de logging estructurado para errores | Media | Difícil diagnóstico en producción | Creación de `App\Services\AppLogger` (error, warning, info) con salida en `tmp/logs/app.log` en formato JSON Lines; uso en `Router::handleError` en prod | Trazabilidad y análisis de fallos |

---

## Detalle por área

### Seguridad

- **Path traversal**: Añadido helper `ensurePathUnderBase($targetPath, $basePath)` en `CarpetasController`, que usa `realpath()` y comprueba que la ruta objetivo esté bajo la base; se utiliza en `ver()`, `verImagen()` y `verAvatar()`.
- **Headers**: Envío de cabeceras de seguridad al inicio de cada petición en el punto de entrada.
- **Errores en producción**: El mensaje de excepción solo se muestra cuando `APP_ENV !== 'prod'`; en prod se registra en `AppLogger` y se devuelve un mensaje genérico.

### Rendimiento

- **N+1**: Nuevo método `ImagenesIndex::loadExistingMd5Set($ws)` que ejecuta una sola consulta para obtener todos los MD5 existentes; en los bucles de sincronización y upsert se usa este set en memoria en lugar de una consulta por archivo. Los MD5 recién insertados se añaden al set dentro del mismo flujo para dedupe en lote.

### Mantenibilidad

- **Router**: `runController()` centraliza la instanciación y la llamada al método del controlador, eliminando la repetición en las tres ramas del router.
- **Configuración**: Uso de `.env` (opcional) y `env.example` como plantilla; `config/mysql_app.json` excluido del control de versiones para no subir credenciales.

### Operación

- **AppLogger**: Servicio de log de aplicación en `tmp/logs/app.log` (JSON Lines), independiente del log de usuario/dashboard (`LogService`). Permite analizar errores y contexto sin depender del mensaje enviado al cliente.

---

## No implementado en esta iteración (plan futuro)

- Autenticación o middleware de autorización para rutas sensibles (setup, workspace_global_config_save, etc.).
- Unificación del esquema MySQL/SQLite en una única definición (DSL o array) con traductores por motor.
- Capa de aplicación (handlers/use cases) e inyección de dependencias.
- Cambio de estructura de carpetas hacia Clean Architecture (Application, Domain, Infrastructure).
- Cola de trabajos para reindexación pesada o worker separado.

Estos puntos quedan documentados en el plan de mejora y en el README (roadmap).
