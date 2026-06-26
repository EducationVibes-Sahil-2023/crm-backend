<?php

namespace App\Models;

use CodeIgniter\Model;

class InventoryItemModel extends Model
{
    protected $table            = 'inventory_items';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'sku', 'name', 'category', 'description', 'unit', 'quantity',
        'reorder_level', 'unit_price', 'location', 'supplier', 'image_url',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'name' => 'required|string|max_length[255]',
    ];

    /** Editable fields accepted from create/update payloads. */
    public const FIELDS = [
        'sku', 'name', 'category', 'description', 'unit', 'quantity',
        'reorder_level', 'unit_price', 'location', 'supplier', 'image_url',
    ];
}
