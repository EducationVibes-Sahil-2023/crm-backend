<?php

namespace App\Models;

use CodeIgniter\Model;

class AssetModel extends Model
{
    protected $table            = 'assets';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'tag', 'name', 'category', 'image_url', 'description', 'serial_number', 'manufacturer',
        'model', 'location', 'condition', 'vendor', 'owner_name', 'owner_email',
        'purchase_date', 'purchase_cost', 'bill_url', 'repair_cost', 'warranty_years',
        'warranty_expiry', 'warranty_doc_url', 'status', 'reject_reason', 'verified_by', 'verified_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'name' => 'required|string|max_length[255]',
    ];

    /** Fields a user may edit while filling in / correcting an asset. */
    public const EDITABLE_FIELDS = [
        'tag', 'name', 'category', 'image_url', 'description', 'serial_number', 'manufacturer',
        'model', 'location', 'condition', 'vendor', 'owner_name', 'owner_email',
        'purchase_date', 'purchase_cost', 'bill_url', 'repair_cost', 'warranty_years',
        'warranty_expiry', 'warranty_doc_url',
    ];

    /** Statuses in which the asset information can still be edited. */
    public const EDITABLE_STATUSES = ['pending', 'rejected'];
}
