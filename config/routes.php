<?php

/**
 * Configuración de Rutas de la Aplicación
 * 
 * Define todas las rutas disponibles en el sistema.
 * Formato: 'ruta' => ['controller' => 'NombreController', 'method' => 'nombreMetodo']
 */

return [
    // Wizard inicial: elegir motor de BD (una sola vez; aplica a todos los workspaces)
    'storage_engine_select' => [
        'controller' => 'StorageEngineController',
        'method' => 'index',
        'type' => 'view'
    ],
    'storage_engine_save' => [
        'controller' => 'StorageEngineController',
        'method' => 'save',
        'type' => 'api'
    ],
    // Workspace selection (obligatorio antes del resto)
    'workspace_select' => [
        'controller' => 'WorkspaceController',
        'method' => 'index',
        'type' => 'view'
    ],
    'workspace_create' => [
        'controller' => 'WorkspaceController',
        'method' => 'create',
        'type' => 'api'
    ],
    'workspace_set' => [
        'controller' => 'WorkspaceController',
        'method' => 'set',
        'type' => 'api'
    ],
    'workspace_delete' => [
        'controller' => 'WorkspaceController',
        'method' => 'delete',
        'type' => 'api'
    ],
    'buscar_carpetas_global' => [
        'controller' => 'WorkspaceController',
        'method' => 'buscarCarpetasConsolidado',
        'type' => 'api'
    ],
    'etiquetas_detectadas_global' => [
        'controller' => 'WorkspaceController',
        'method' => 'etiquetasDetectadasConsolidado',
        'type' => 'api'
    ],
    'buscar_imagenes_etiquetas_global' => [
        'controller' => 'WorkspaceController',
        'method' => 'buscarImagenesEtiquetasConsolidado',
        'type' => 'api'
    ],
    'workspace_global_config' => [
        'controller' => 'WorkspaceController',
        'method' => 'globalConfig',
        'type' => 'view'
    ],
    'workspace_global_config_save' => [
        'controller' => 'WorkspaceController',
        'method' => 'globalConfigSave',
        'type' => 'api'
    ],
    // API Routes
    'obtener_tablas' => [
        'controller' => 'TablasController',
        'method' => 'obtener',
        'type' => 'api'
    ],
    'procesar' => [
        'controller' => 'ProcesarController',
        'method' => 'procesar',
        'type' => 'api'
    ],
    'procesar_imagenes' => [
        'controller' => 'ImagenesController',
        'method' => 'procesarSiguiente',
        'type' => 'api'
    ],
    'estadisticas_clasificacion' => [
        'controller' => 'ImagenesController',
        'method' => 'estadisticas',
        'type' => 'api'
    ],
    'estadisticas_descarga' => [
        'controller' => 'ProcesarController',
        'method' => 'estadisticasDescarga',
        'type' => 'api'
    ],
    'etiquetas_detectadas' => [
        'controller' => 'ImagenesController',
        'method' => 'etiquetasDetectadas',
        'type' => 'api'
    ],
    'buscar_imagenes_etiquetas' => [
        'controller' => 'ImagenesController',
        'method' => 'buscarPorEtiquetas',
        'type' => 'api'
    ],
    'reindex_imagenes' => [
        'controller' => 'ImagenesController',
        'method' => 'reindexDesdeFilesystem',
        'type' => 'api'
    ],
    'reset_clasificacion' => [
        'controller' => 'ImagenesController',
        'method' => 'resetClasificacion',
        'type' => 'api'
    ],
    'buscar_carpetas' => [
        'controller' => 'CarpetasController',
        'method' => 'buscar',
        'type' => 'api'
    ],
    'ver_carpeta' => [
        'controller' => 'CarpetasController',
        'method' => 'ver',
        'type' => 'api'
    ],
    'ver_imagen' => [
        'controller' => 'CarpetasController',
        'method' => 'verImagen',
        'type' => 'api'
    ],
    'ver_avatar' => [
        'controller' => 'CarpetasController',
        'method' => 'verAvatar',
        'type' => 'api'
    ],
    'imagen_detecciones' => [
        'controller' => 'ImagenesController',
        'method' => 'imagenDetecciones',
        'type' => 'api'
    ],
    'upload_imagenes' => [
        'controller' => 'UploadController',
        'method' => 'uploadImagenes',
        'type' => 'api'
    ],

    // Logs (persistente en tmp/logs)
    'log_append' => [
        'controller' => 'LogController',
        'method' => 'append',
        'type' => 'api'
    ],
    'log_tail' => [
        'controller' => 'LogController',
        'method' => 'tail',
        'type' => 'api'
    ],
    'log_clear' => [
        'controller' => 'LogController',
        'method' => 'clear',
        'type' => 'api'
    ],

    // Setup Routes (configuración en SQLite)
    'setup' => [
        'controller' => 'SetupController',
        'method' => 'index',
        'type' => 'view'
    ],
    'setup_test_db' => [
        'controller' => 'SetupController',
        'method' => 'testDb',
        'type' => 'api'
    ],
    'setup_test_schema' => [
        'controller' => 'SetupController',
        'method' => 'testSchema',
        'type' => 'api'
    ],
    'setup_test_dir' => [
        'controller' => 'SetupController',
        'method' => 'testDir',
        'type' => 'api'
    ],
    'setup_test_clasificador' => [
        'controller' => 'SetupController',
        'method' => 'testClasificador',
        'type' => 'api'
    ],
    'setup_save' => [
        'controller' => 'SetupController',
        'method' => 'save',
        'type' => 'api'
    ],
    'setup_save_section' => [
        'controller' => 'SetupController',
        'method' => 'saveSection',
        'type' => 'api'
    ],
    
    // View Routes
    'index' => [
        'controller' => 'HomeController',
        'method' => 'index',
        'type' => 'view'
    ],
];
