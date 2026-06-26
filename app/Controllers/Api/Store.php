<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;
use Config\Database;

/**
 * Generic per-workspace JSON document store (the `app_store` table). The whole
 * front-end loads its data from here on sign-in and writes each module's blob
 * back — so nothing lives in browser storage. Auth-protected (see Filters).
 */
class Store extends ResourceController
{
    protected $format = 'json';

    /** GET /api/store — every stored blob in one payload to hydrate the app. */
    public function index()
    {
        $out = [];
        try {
            $rows = Database::connect()->table('app_store')->get()->getResultArray();
            foreach ($rows as $r) {
                $decoded = json_decode((string) ($r['data'] ?? 'null'), true);
                $out[(string) $r['store_key']] = $decoded;
            }
        } catch (\Throwable $e) {
            // table missing — return an empty map so the client falls back to defaults
        }

        return $this->respond(['data' => (object) $out]);
    }

    /** GET /api/store/<key> — a single stored blob. */
    public function show($key = null)
    {
        $key = $this->cleanKey((string) $key);
        if ($key === '') {
            return $this->failValidationErrors('A store key is required.');
        }
        $row = Database::connect()->table('app_store')->where('store_key', $key)->get()->getRowArray();

        return $this->respond(['key' => $key, 'data' => $row ? json_decode((string) $row['data'], true) : null]);
    }

    /** PUT /api/store/<key> — upsert one blob (the raw JSON body is the value). */
    public function update($key = null)
    {
        $key = $this->cleanKey((string) $key);
        if ($key === '') {
            return $this->failValidationErrors('A store key is required.');
        }
        // The value is the JSON body's `data` field, or the whole body if absent.
        $body  = $this->request->getJSON(true);
        $value = (is_array($body) && array_key_exists('data', $body)) ? $body['data'] : $body;

        $db   = Database::connect();
        $data = ['data' => json_encode($value), 'updated_at' => date('Y-m-d H:i:s')];
        if ($db->table('app_store')->where('store_key', $key)->countAllResults() > 0) {
            $db->table('app_store')->where('store_key', $key)->update($data);
        } else {
            $db->table('app_store')->insert(['store_key' => $key] + $data);
        }

        return $this->respond(['saved' => true, 'key' => $key]);
    }

    /** DELETE /api/store/<key> — remove a blob. */
    public function delete($key = null)
    {
        $key = $this->cleanKey((string) $key);
        if ($key === '') {
            return $this->failValidationErrors('A store key is required.');
        }
        Database::connect()->table('app_store')->where('store_key', $key)->delete();

        return $this->respondDeleted(['key' => $key]);
    }

    private function cleanKey(string $key): string
    {
        return preg_replace('/[^a-zA-Z0-9_.:-]/', '', $key) ?? '';
    }
}
