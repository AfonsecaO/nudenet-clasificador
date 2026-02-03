<?php

namespace App\Controllers;

use App\Services\LogService;

class LogController extends BaseController
{
    public function append()
    {
        $raw = file_get_contents('php://input');
        $p = json_decode($raw ?: '', true);
        if (!is_array($p)) $p = [];

        $type = isset($p['type']) ? (string)$p['type'] : 'info';
        $message = isset($p['message']) ? (string)$p['message'] : '';
        $meta = (isset($p['meta']) && is_array($p['meta'])) ? $p['meta'] : null;

        if (trim($message) === '') {
            $this->jsonResponse(['success' => false, 'ok' => false, 'error' => 'message vacío'], 400);
        }

        $entry = [
            'type' => $type,
            'message' => $message,
        ];
        if ($meta !== null) $entry['meta'] = $meta;

        LogService::append($entry);
        $this->jsonResponse(['success' => true, 'ok' => true]);
    }

    public function tail()
    {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        $items = LogService::tail($limit);
        $this->jsonResponse(['success' => true, 'ok' => true, 'items' => $items]);
    }

    public function clear()
    {
        LogService::clear();
        $this->jsonResponse(['success' => true, 'ok' => true]);
    }
}

