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
        $this->render('storage_engine_select', []);
    }

    public function save()
    {
        $driver = isset($_POST['driver']) ? trim((string) $_POST['driver']) : '';
        if ($driver !== 'mysql') {
            $driver = 'sqlite';
        }
        StorageEngineConfig::setStorageEngine($driver);
        header('Location: ?action=workspace_select');
        exit;
    }
}
