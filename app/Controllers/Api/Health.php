<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;
use Config\Database;
use Throwable;

class Health extends ResourceController
{
    protected $format = 'json';

    public function index()
    {
        $db = 'down';
        try {
            Database::connect()->query('SELECT 1');
            $db = 'up';
        } catch (Throwable $e) {
            $db = 'down';
        }

        return $this->respond([
            'status'  => 'ok',
            'service' => 'dashboard-backend',
            'database' => $db,
        ]);
    }
}
