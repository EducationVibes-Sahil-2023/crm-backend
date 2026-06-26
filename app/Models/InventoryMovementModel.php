<?php

namespace App\Models;

use CodeIgniter\Model;

class InventoryMovementModel extends Model
{
    protected $table            = 'inventory_movements';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = ['item_id', 'type', 'qty', 'balance_after', 'reason', 'actor'];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = '';

    /** @return list<array> movements for an item, newest first */
    public function forItem(int $itemId): array
    {
        return $this->where('item_id', $itemId)->orderBy('id', 'DESC')->findAll();
    }
}
