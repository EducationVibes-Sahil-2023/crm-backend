<?php

namespace App\Models;

use CodeIgniter\Model;

class DirectoryModel extends Model
{
    protected $table         = 'directory';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';

    protected $allowedFields = [
        'name', 'email', 'phone', 'designation', 'department', 'role', 'status',
        'bio', 'profile', 'password', 'linkedin', 'twitter', 'github',
        'joining_date', 'employee_id', 'company_code', 'city', 'state',
        'address', 'zip', 'extra_permissions',
    ];
}
