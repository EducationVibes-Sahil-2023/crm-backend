<?php

namespace App\Models;

use CodeIgniter\Model;

class InventoryAssignmentModel extends Model
{
    protected $table            = 'inventory_assignments';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'item_id', 'assignee_name', 'assignee_email', 'qty', 'qty_returned',
        'status', 'note', 'asset_id', 'issued_by', 'issued_at', 'returned_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /** @return list<array> assignments for an item, newest first */
    public function forItem(int $itemId): array
    {
        return $this->where('item_id', $itemId)->orderBy('id', 'DESC')->findAll();
    }

    /** Outstanding (not-yet-returned) units per item id. @return array<int,int> */
    public function outstandingByItem(): array
    {
        $rows = $this->select('item_id, SUM(qty - qty_returned) AS outstanding')
            ->groupBy('item_id')
            ->findAll();
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['item_id']] = (int) $r['outstanding'];
        }

        return $map;
    }
}
