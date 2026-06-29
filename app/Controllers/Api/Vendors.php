<?php

namespace App\Controllers\Api;

use App\Models\VendorModel;
use CodeIgniter\RESTful\ResourceController;

/**
 * Vendors — supplier directory (the `vendors` table). Resourceful CRUD with a
 * simple text/category/status filter. Auth-protected (see Filters: api/vendors*).
 */
class Vendors extends ResourceController
{
    protected $modelName = VendorModel::class;
    protected $format    = 'json';

    /** GET /api/vendors?q=&category=&status= */
    public function index()
    {
        $m = $this->model;

        foreach (['category', 'status'] as $f) {
            $v = (string) ($this->request->getGet($f) ?? '');
            if ($v !== '') {
                $m->where($f, $v);
            }
        }
        $q = trim((string) $this->request->getGet('q'));
        if ($q !== '') {
            $m->groupStart()
                ->like('name', $q)->orLike('email', $q)->orLike('contact_person', $q)->orLike('city', $q)
                ->groupEnd();
        }

        $rows = $m->orderBy('id', 'DESC')->findAll();

        return $this->respond(['vendors' => array_map([$this, 'present'], $rows)]);
    }

    /** POST /api/vendors */
    public function create()
    {
        $id = $this->model->insert($this->fields($this->request->getJSON(true) ?? []), true);
        if ($id === false) {
            return $this->failValidationErrors($this->model->errors());
        }

        return $this->respondCreated(['vendor' => $this->present($this->model->find($id))]);
    }

    /** PUT/PATCH /api/vendors/{id} */
    public function update($id = null)
    {
        if ($this->model->find((int) $id) === null) {
            return $this->failNotFound('Vendor not found.');
        }
        $this->model->update((int) $id, $this->fields($this->request->getJSON(true) ?? []));

        return $this->respond(['vendor' => $this->present($this->model->find((int) $id))]);
    }

    /** DELETE /api/vendors/{id} */
    public function delete($id = null)
    {
        if ($this->model->find((int) $id) === null) {
            return $this->failNotFound('Vendor not found.');
        }
        $this->model->delete((int) $id);

        return $this->respondDeleted(['id' => (int) $id]);
    }

    private function fields(array $in): array
    {
        $out = [];
        foreach ($this->model->allowedFields as $f) {
            if (array_key_exists($f, $in)) {
                $out[$f] = $in[$f];
            }
        }

        return $out;
    }

    private function present(array $row): array
    {
        $row['id'] = (string) $row['id'];

        return $row;
    }
}
