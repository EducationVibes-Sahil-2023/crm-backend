<?php

namespace App\Controllers\Api;

use CodeIgniter\Database\Config as DbConfig;
use CodeIgniter\RESTful\ResourceController;

/**
 * Lead-setup / lookup lists (status, source, type, sub-status, department,
 * designation, ticket category/priority, asset category) — real MySQL rows in
 * the current workspace's database. The JwtAuth filter has already pointed the
 * default connection at the caller's tenant DB, so every query is workspace-
 * scoped. Tables are ensured on first use so existing tenant DBs pick them up
 * without a manual migration.
 */
class Config extends ResourceController
{
    protected $format = 'json';

    /** kind (frontend) -> table (MySQL). */
    private const KINDS = [
        'status'         => 'lead_statuses',
        'source'         => 'lead_sources',
        'type'           => 'lead_types',
        'subStatus'      => 'lead_sub_statuses',
        'department'     => 'departments',
        'designation'    => 'designations',
        'ticketCategory' => 'ticket_categories',
        'ticketPriority' => 'ticket_priorities',
        'assetCategory'  => 'asset_categories',
    ];

    /** GET /api/config/(:kind) -> [{ id, name, color, sortOrder }] */
    public function listKind(string $kind)
    {
        $table = $this->table($kind);
        if ($table === null) {
            return $this->failValidationErrors('Unknown config kind.');
        }
        $db = $this->ensure($table);

        return $this->respond(['items' => array_map(
            [$this, 'shape'],
            $db->table($table)->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')->get()->getResultArray(),
        )]);
    }

    /** POST /api/config/(:kind) { name, color?, sortOrder? } */
    public function createKind(string $kind)
    {
        $table = $this->table($kind);
        if ($table === null) {
            return $this->failValidationErrors('Unknown config kind.');
        }
        $in   = $this->request->getJSON(true) ?: [];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            return $this->failValidationErrors('Name is required.');
        }
        $db  = $this->ensure($table);
        $now = date('Y-m-d H:i:s');
        $db->table($table)->insert([
            'name'       => $name,
            'color'      => $this->color($in['color'] ?? null),
            'sort_order' => (int) ($in['sortOrder'] ?? $this->nextSort($db, $table)),
            'active'     => 1,
            'created_at' => $now,
        ]);
        $id = (int) $db->insertID();

        return $this->respondCreated($this->shape($db->table($table)->where('id', $id)->get()->getRowArray()));
    }

    /** PUT /api/config/(:kind)/(:id) { name?, color?, sortOrder? } */
    public function updateKind($kind = null, $id = null)
    {
        $table = $this->table((string) $kind);
        if ($table === null) {
            return $this->failValidationErrors('Unknown config kind.');
        }
        $db  = $this->ensure($table);
        $row = $db->table($table)->where('id', (int) $id)->get()->getRowArray();
        if ($row === null) {
            return $this->failNotFound('Not found.');
        }
        $in    = $this->request->getJSON(true) ?: [];
        $patch = [];
        if (array_key_exists('name', $in)) {
            $patch['name'] = trim((string) $in['name']);
        }
        if (array_key_exists('color', $in)) {
            $patch['color'] = $this->color($in['color']);
        }
        if (array_key_exists('sortOrder', $in)) {
            $patch['sort_order'] = (int) $in['sortOrder'];
        }
        if ($patch) {
            $db->table($table)->where('id', (int) $id)->update($patch);
        }

        return $this->respond($this->shape($db->table($table)->where('id', (int) $id)->get()->getRowArray()));
    }

    /** DELETE /api/config/(:kind)/(:id) */
    public function removeKind($kind = null, $id = null)
    {
        $table = $this->table((string) $kind);
        if ($table === null) {
            return $this->failValidationErrors('Unknown config kind.');
        }
        $db = $this->ensure($table);
        $db->table($table)->where('id', (int) $id)->delete();

        return $this->respondDeleted(['ok' => true]);
    }

    /** POST /api/config/(:kind)/reorder { ids: [id,...] } — persist order. */
    public function reorderKind($kind = null)
    {
        $table = $this->table((string) $kind);
        if ($table === null) {
            return $this->failValidationErrors('Unknown config kind.');
        }
        $db  = $this->ensure($table);
        $ids = (array) (($this->request->getJSON(true) ?: [])['ids'] ?? []);
        foreach (array_values($ids) as $i => $id) {
            $db->table($table)->where('id', (int) $id)->update(['sort_order' => $i]);
        }

        return $this->respond(['ok' => true]);
    }

    // ---- helpers ----

    private function table(string $kind): ?string
    {
        return self::KINDS[$kind] ?? null;
    }

    private function color($v): ?string
    {
        $c = trim((string) ($v ?? ''));

        return $c === '' ? null : substr($c, 0, 20);
    }

    private function shape(?array $r): array
    {
        return [
            'id'        => (string) ($r['id'] ?? ''),
            'name'      => (string) ($r['name'] ?? ''),
            'color'     => (string) ($r['color'] ?? ''),
            'sortOrder' => (int) ($r['sort_order'] ?? 0),
        ];
    }

    private function nextSort(\CodeIgniter\Database\BaseConnection $db, string $table): int
    {
        return (int) ($db->table($table)->selectMax('sort_order', 'm')->get()->getRowArray()['m'] ?? -1) + 1;
    }

    /** Ensure the list table (+ `color` column) exists in the current DB. */
    private function ensure(string $table): \CodeIgniter\Database\BaseConnection
    {
        $db = DbConfig::connect();
        $db->query("CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(120) NULL,
            `color` VARCHAR(20) NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `active` TINYINT NOT NULL DEFAULT 1,
            `created_at` DATETIME NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $hasColor = (int) ($db->query(
            "SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'color'",
            [$table],
        )->getRowArray()['c'] ?? 0);
        if ($hasColor === 0) {
            $db->query("ALTER TABLE `{$table}` ADD COLUMN `color` VARCHAR(20) NULL AFTER `name`");
        }

        return $db;
    }
}
