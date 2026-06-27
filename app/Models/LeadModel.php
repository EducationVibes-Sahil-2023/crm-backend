<?php

namespace App\Models;

use CodeIgniter\Model;

class LeadModel extends Model
{
    protected $table            = 'leads';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';

    protected $allowedFields = [
        'name', 'company', 'phone', 'alt_phone', 'email', 'city', 'state',
        'status', 'source', 'type', 'channel', 'reference_name', 'assigned_to',
        'created_by', 'follow_up_date', 'connected_date', 'assignation_date',
        'response_time', 'total_call_count', 'call_count', 'duration', 'form_id',
        'custom', 'deleted',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
}
