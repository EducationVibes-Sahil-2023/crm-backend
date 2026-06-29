<?php

namespace App\Models;

use CodeIgniter\Model;

class VendorModel extends Model
{
    protected $table         = 'vendors';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'name', 'contact_person', 'email', 'phone', 'category', 'gstin',
        'website', 'address', 'city', 'state', 'zip', 'country',
        'payment_terms', 'status', 'notes',
    ];
}
