<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Audit trail across the app (the `activity_log` table). `meta` is a JSON blob
 * for extra context (e.g. the action target). created_at is supplied by the
 * caller so imported/relayed events keep their original time.
 */
class ActivityLogModel extends Model
{
    protected $table         = 'activity_log';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = ['category', 'action', 'actor', 'meta', 'created_at'];
}
