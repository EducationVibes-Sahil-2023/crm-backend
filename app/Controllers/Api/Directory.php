<?php

namespace App\Controllers\Api;

use App\Models\DirectoryModel;
use CodeIgniter\RESTful\ResourceController;

/**
 * Team directory (the "Users" page). DB-backed and tenant-routed: the JwtAuth
 * filter points the connection at the caller's database, so each client only
 * sees and edits their own people.
 */
class Directory extends ResourceController
{
    protected $modelName = DirectoryModel::class;
    protected $format    = 'json';

    /** GET /api/directory */
    public function index()
    {
        return $this->respond($this->model->orderBy('id', 'DESC')->findAll());
    }

    /** POST /api/directory */
    public function create()
    {
        $data = $this->clean($this->request->getJSON(true) ?? []);
        if (($data['name'] ?? '') === '' || ($data['email'] ?? '') === '') {
            return $this->failValidationErrors('Name and email are required.');
        }
        $id = $this->model->insert($data, true);
        return $this->respondCreated($this->model->find($id));
    }

    /** PUT /api/directory/{id} */
    public function update($id = null)
    {
        if (! $id || $this->model->find($id) === null) {
            return $this->failNotFound('Directory entry not found.');
        }
        $this->model->update($id, $this->clean($this->request->getJSON(true) ?? []));
        return $this->respond($this->model->find($id));
    }

    /** DELETE /api/directory/{id} */
    public function delete($id = null)
    {
        if (! $id || $this->model->find($id) === null) {
            return $this->failNotFound('Directory entry not found.');
        }
        $this->model->delete($id);
        return $this->respondDeleted(['id' => (int) $id]);
    }

    /** Keep only known columns; JSON-encode extra_permissions. */
    private function clean(array $in): array
    {
        $out = [];
        foreach ($this->model->allowedFields as $f) {
            if (array_key_exists($f, $in)) {
                $out[$f] = $f === 'extra_permissions' && is_array($in[$f]) ? json_encode($in[$f]) : $in[$f];
            }
        }
        return $out;
    }
}
