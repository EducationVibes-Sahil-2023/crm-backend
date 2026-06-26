<?php

namespace App\Controllers\Api;

use App\Models\MediaFileModel;
use CodeIgniter\RESTful\ResourceController;

class MediaFiles extends ResourceController
{
    protected $modelName = MediaFileModel::class;
    protected $format    = 'json';

    private const UPLOAD_DIR = 'uploads/media/';

    /** GET /api/media/files */
    public function index()
    {
        $rows = $this->model->orderBy('id', 'DESC')->findAll();

        return $this->respond(array_map([$this, 'present'], $rows));
    }

    /**
     * POST /api/media/files — multipart upload.
     * Fields: file[] (one or many), folder_id (optional)
     */
    public function create()
    {
        $folderId = $this->normalizeId($this->request->getPost('folder_id'));
        $uploaded = $this->request->getFileMultiple('file');
        if (! $uploaded) {
            $single   = $this->request->getFile('file');
            $uploaded = $single ? [$single] : [];
        }

        if ($uploaded === []) {
            return $this->failValidationErrors(['file' => 'No file was uploaded.']);
        }

        $destDir = FCPATH . self::UPLOAD_DIR;
        if (! is_dir($destDir)) {
            mkdir($destDir, 0775, true);
        }

        $saved = [];
        foreach ($uploaded as $file) {
            if (! $file->isValid() || $file->hasMoved()) {
                continue;
            }

            $originalName = $file->getClientName();
            $newName      = $file->getRandomName();
            $file->move($destDir, $newName);

            $id = $this->model->insert([
                'name'      => $originalName,
                'folder_id' => $folderId,
                'mime'      => $file->getClientMimeType() ?: 'application/octet-stream',
                'size'      => filesize($destDir . $newName) ?: 0,
                'path'      => self::UPLOAD_DIR . $newName,
            ], true);

            if ($id) {
                $saved[] = $this->present($this->model->find($id));
            }
        }

        if ($saved === []) {
            return $this->failServerError('Upload failed.');
        }

        return $this->respondCreated($saved);
    }

    /** PUT /api/media/files/{id} — rename or move. { name?, folder_id? } */
    public function update($id = null)
    {
        if ($this->model->find($id) === null) {
            return $this->failNotFound("File #{$id} not found");
        }

        $input = $this->request->getJSON(true) ?? $this->request->getRawInput();
        $data  = [];
        if (array_key_exists('name', $input)) {
            $data['name'] = trim((string) $input['name']);
        }
        if (array_key_exists('folder_id', $input)) {
            $data['folder_id'] = $this->normalizeId($input['folder_id']);
        }

        if ($data === [] || ! $this->model->update($id, $data)) {
            return $this->failValidationErrors($this->model->errors() ?: ['update' => 'Nothing to update']);
        }

        return $this->respond($this->present($this->model->find($id)));
    }

    /** DELETE /api/media/files/{id} */
    public function delete($id = null)
    {
        $row = $this->model->find($id);
        if ($row === null) {
            return $this->failNotFound("File #{$id} not found");
        }

        $abs = FCPATH . $row['path'];
        if (is_file($abs)) {
            @unlink($abs);
        }
        $this->model->delete($id);

        return $this->respondDeleted(['id' => $id]);
    }

    /** Attach a fully-qualified, request-derived URL to a stored row. */
    private function present(array $row): array
    {
        $uri  = $this->request->getUri();
        $base = $uri->getScheme() . '://' . $uri->getAuthority();
        $row['url'] = $base . '/' . ltrim($row['path'], '/');

        return $row;
    }

    private function normalizeId($value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        return (int) $value;
    }
}
