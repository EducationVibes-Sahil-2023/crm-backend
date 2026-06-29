<?php

namespace App\Controllers\Api;

use App\Models\ActivityLogModel;
use CodeIgniter\RESTful\ResourceController;

/**
 * Activity log — the workspace audit trail (the `activity_log` table). Replaces
 * the old app_store JSON blob with real, queryable rows. Auth-protected
 * (see Filters: api/activity*).
 */
class ActivityLog extends ResourceController
{
    protected $modelName = ActivityLogModel::class;
    protected $format    = 'json';

    /** GET /api/activity?category=&actor=&q=&limit= */
    public function index()
    {
        $m = $this->model;

        $cat = (string) ($this->request->getGet('category') ?? '');
        if ($cat !== '' && $cat !== 'all') {
            $m->where('category', $cat);
        }
        $actor = (string) ($this->request->getGet('actor') ?? '');
        if ($actor !== '') {
            $m->where('actor', $actor);
        }
        $q = trim((string) $this->request->getGet('q'));
        if ($q !== '') {
            $m->groupStart()->like('action', $q)->orLike('meta', $q)->groupEnd();
        }

        $limit = max(1, min(5000, (int) ($this->request->getGet('limit') ?? 1000)));
        $rows  = $m->orderBy('id', 'DESC')->findAll($limit);

        return $this->respond(['activities' => array_map([$this, 'present'], $rows)]);
    }

    /** POST /api/activity  { category, action, actor, meta?, created_at? } */
    public function create()
    {
        $in   = $this->request->getJSON(true) ?? [];
        $meta = $in['meta'] ?? null;

        $id = $this->model->insert([
            'category'   => $in['category'] ?? 'system',
            'action'     => (string) ($in['action'] ?? ''),
            'actor'      => $in['actor'] ?? 'System',
            'meta'       => is_array($meta) ? json_encode($meta) : $meta,
            'created_at' => $in['created_at'] ?? date('Y-m-d H:i:s'),
        ], true);

        if ($id === false) {
            return $this->failValidationErrors($this->model->errors());
        }

        return $this->respondCreated(['activity' => $this->present($this->model->find($id))]);
    }

    /** DELETE /api/activity — clear the whole log. */
    public function clear()
    {
        $this->model->builder()->truncate();

        return $this->respondDeleted(['cleared' => true]);
    }

    private function present(array $row): array
    {
        $row['id']   = (string) $row['id'];
        $row['meta'] = $row['meta'] ? json_decode((string) $row['meta'], true) : new \stdClass();

        return $row;
    }
}
