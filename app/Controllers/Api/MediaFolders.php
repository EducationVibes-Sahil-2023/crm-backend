<?php

namespace App\Controllers\Api;

use App\Models\MediaFileModel;
use App\Models\MediaFolderModel;
use CodeIgniter\RESTful\ResourceController;

class MediaFolders extends ResourceController
{
    protected $modelName = MediaFolderModel::class;
    protected $format    = 'json';

    /** GET /api/media/folders */
    public function index()
    {
        return $this->respond($this->model->orderBy('name', 'ASC')->findAll());
    }

    /** POST /api/media/folders  { name, parent_id? } */
    public function create()
    {
        $data = $this->request->getJSON(true) ?? $this->request->getPost();
        $data = [
            'name'      => trim((string) ($data['name'] ?? '')),
            'parent_id' => $this->normalizeId($data['parent_id'] ?? null),
        ];

        if (! $this->model->insert($data)) {
            return $this->failValidationErrors($this->model->errors());
        }

        return $this->respondCreated($this->model->find($this->model->getInsertID()));
    }

    /** PUT /api/media/folders/{id}  { name?, parent_id? } */
    public function update($id = null)
    {
        if ($this->model->find($id) === null) {
            return $this->failNotFound("Folder #{$id} not found");
        }

        $input = $this->request->getJSON(true) ?? $this->request->getRawInput();
        $data  = [];
        if (array_key_exists('name', $input)) {
            $data['name'] = trim((string) $input['name']);
        }
        if (array_key_exists('parent_id', $input)) {
            $target = $this->normalizeId($input['parent_id']);
            // Disallow moving a folder into itself or one of its descendants.
            if ($target !== null && in_array($target, $this->model->descendantIds((int) $id), true)) {
                return $this->failValidationErrors(['parent_id' => 'Cannot move a folder inside itself.']);
            }
            $data['parent_id'] = $target;
        }

        if ($data === [] || ! $this->model->update($id, $data)) {
            return $this->failValidationErrors($this->model->errors() ?: ['update' => 'Nothing to update']);
        }

        return $this->respond($this->model->find($id));
    }

    /** DELETE /api/media/folders/{id} — removes nested folders + their files. */
    public function delete($id = null)
    {
        if ($this->model->find($id) === null) {
            return $this->failNotFound("Folder #{$id} not found");
        }

        $ids   = $this->model->descendantIds((int) $id);
        $files = new MediaFileModel();

        // Unlink the physical files before clearing rows.
        $rows = $files->whereIn('folder_id', $ids)->findAll();
        foreach ($rows as $row) {
            $abs = FCPATH . $row['path'];
            if (is_file($abs)) {
                @unlink($abs);
            }
        }

        $files->whereIn('folder_id', $ids)->delete();
        $this->model->whereIn('id', $ids)->delete();

        return $this->respondDeleted(['id' => $id, 'removed' => $ids]);
    }

    private function normalizeId($value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        return (int) $value;
    }
}
