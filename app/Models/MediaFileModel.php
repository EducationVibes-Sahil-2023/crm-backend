<?php

namespace App\Models;

use CodeIgniter\Model;

class MediaFileModel extends Model
{
    protected $table            = 'media_files';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = ['name', 'folder_id', 'mime', 'size', 'path'];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'name'      => 'required|string|max_length[255]',
        'folder_id' => 'permit_empty|is_natural_no_zero',
        'path'      => 'required|string|max_length[255]',
    ];
}
