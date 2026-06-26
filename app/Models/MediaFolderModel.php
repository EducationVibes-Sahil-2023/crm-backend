<?php

namespace App\Models;

use CodeIgniter\Model;

class MediaFolderModel extends Model
{
    protected $table            = 'media_folders';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = ['name', 'parent_id'];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'name'      => 'required|string|max_length[255]',
        'parent_id' => 'permit_empty|is_natural_no_zero',
    ];

    /**
     * Collect the ids of a folder plus every folder nested beneath it.
     *
     * @return list<int>
     */
    public function descendantIds(int $id): array
    {
        $all   = $this->select('id, parent_id')->findAll();
        $ids   = [$id];
        $grew  = true;
        while ($grew) {
            $grew = false;
            foreach ($all as $f) {
                $pid = $f['parent_id'] === null ? null : (int) $f['parent_id'];
                if ($pid !== null && in_array($pid, $ids, true) && ! in_array((int) $f['id'], $ids, true)) {
                    $ids[]  = (int) $f['id'];
                    $grew   = true;
                }
            }
        }

        return $ids;
    }
}
