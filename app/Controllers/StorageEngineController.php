<?php

namespace App\Controllers;

use App\Services\StorageEngineConfig;

class StorageEngineController extends BaseController
{
    public function index()
    {
        if (StorageEngineConfig::storageEngineFileExists()) {
            header('Location: ?action=workspace_select');
            exit;
        }
        $this->render('storage_engine_select', [
            'registros_descarga' => StorageEngineConfig::getRegistrosDescarga(),
            'registros_moderacion' => StorageEngineConfig::getRegistrosModeracion(),
        ]);
    }

    public function save()
    {
        $driver = isset($_POST['driver']) ? trim((string) $_POST['driver']) : '';
        if ($driver !== 'mysql') {
            $driver = 'sqlite';
        }
        StorageEngineConfig::setStorageEngine($driver);
        $n = isset($_POST['registros_descarga']) ? (int) $_POST['registros_descarga'] : 1;
        $n = max(1, min(1000, $n));
        StorageEngineConfig::setRegistrosDescarga($n);
        $nMod = isset($_POST['registros_moderacion']) ? (int) $_POST['registros_moderacion'] : 20;
        $nMod = max(1, min(100, $nMod));
        StorageEngineConfig::setRegistrosModeracion($nMod);
        header('Location: ?action=workspace_select');
        exit;
    }
}
