<?php

namespace App\Controllers\Api;

use App\Models\TaskModel;
use CodeIgniter\RESTful\ResourceController;

class Tasks extends ResourceController
{
    protected $modelName = TaskModel::class;
    protected $format    = 'json';

    /** GET /api/tasks */
    public function index()
    {
        return $this->respond($this->model->orderBy('id', 'DESC')->findAll());
    }

    /** GET /api/tasks/{id} */
    public function show($id = null)
    {
        $task = $this->model->find($id);
        if ($task === null) {
            return $this->failNotFound("Task #{$id} not found");
        }

        return $this->respond($task);
    }

    /** POST /api/tasks */
    public function create()
    {
        $data = $this->request->getJSON(true) ?? $this->request->getPost();

        if (! $this->model->insert($data)) {
            return $this->failValidationErrors($this->model->errors());
        }

        return $this->respondCreated($this->model->find($this->model->getInsertID()));
    }

    /** PUT/PATCH /api/tasks/{id} */
    public function update($id = null)
    {
        if ($this->model->find($id) === null) {
            return $this->failNotFound("Task #{$id} not found");
        }

        $data = $this->request->getJSON(true) ?? $this->request->getRawInput();

        if (! $this->model->update($id, $data)) {
            return $this->failValidationErrors($this->model->errors());
        }

        return $this->respond($this->model->find($id));
    }

    /** DELETE /api/tasks/{id} */
    public function delete($id = null)
    {
        if ($this->model->find($id) === null) {
            return $this->failNotFound("Task #{$id} not found");
        }

        $this->model->delete($id);

        return $this->respondDeleted(['id' => $id]);
    }
}
