<?php

namespace App\Controllers\Api;

use App\Models\LeadModel;
use CodeIgniter\RESTful\ResourceController;

/**
 * CRM Leads — the first fully-normalised domain (the `leads` table). Resourceful
 * CRUD + list with simple filters. Auth-protected (see Filters: api/leads*).
 */
class Leads extends ResourceController
{
    protected $modelName = LeadModel::class;
    protected $format    = 'json';

    /** GET /api/leads?status=&source=&assigned_to=&q=&deleted=0 */
    public function index()
    {
        $m = $this->model->where('deleted', (int) ($this->request->getGet('deleted') ?? 0));

        foreach (['status', 'source', 'type', 'assigned_to'] as $f) {
            $v = $this->request->getGet($f);
            if ($v !== null && $v !== '') {
                $m->where($f, $v);
            }
        }
        $q = trim((string) $this->request->getGet('q'));
        if ($q !== '') {
            $m->groupStart()
                ->like('name', $q)->orLike('company', $q)->orLike('email', $q)->orLike('phone', $q)
                ->groupEnd();
        }

        $rows = $m->orderBy('id', 'DESC')->findAll();

        return $this->respond(['leads' => array_map([$this, 'present'], $rows)]);
    }

    /** GET /api/leads/{id} */
    public function show($id = null)
    {
        $row = $this->model->find((int) $id);
        if ($row === null) {
            return $this->failNotFound('Lead not found.');
        }

        return $this->respond(['lead' => $this->present($row)]);
    }

    /** POST /api/leads */
    public function create()
    {
        $id = $this->model->insert($this->fields($this->request->getJSON(true) ?? []), true);
        if ($id === false) {
            return $this->failValidationErrors($this->model->errors());
        }

        return $this->respondCreated(['lead' => $this->present($this->model->find($id))]);
    }

    /** PUT/PATCH /api/leads/{id} */
    public function update($id = null)
    {
        if ($this->model->find((int) $id) === null) {
            return $this->failNotFound('Lead not found.');
        }
        $this->model->update((int) $id, $this->fields($this->request->getJSON(true) ?? []));

        return $this->respond(['lead' => $this->present($this->model->find((int) $id))]);
    }

    /** DELETE /api/leads/{id} — soft delete (set deleted = 1). */
    public function delete($id = null)
    {
        if ($this->model->find((int) $id) === null) {
            return $this->failNotFound('Lead not found.');
        }
        $this->model->update((int) $id, ['deleted' => 1]);

        return $this->respondDeleted(['id' => (int) $id]);
    }

    /** Whitelist + encode the JSON `custom` field for storage. */
    private function fields(array $in): array
    {
        $out = [];
        foreach ($this->model->allowedFields as $f) {
            if (array_key_exists($f, $in)) {
                $out[$f] = $f === 'custom' && is_array($in[$f]) ? json_encode($in[$f]) : $in[$f];
            }
        }

        return $out;
    }

    /** Decode JSON columns for the client. */
    private function present(array $row): array
    {
        $row['custom'] = $row['custom'] ? json_decode((string) $row['custom'], true) : new \stdClass();
        $row['id']     = (string) $row['id'];

        return $row;
    }
}
