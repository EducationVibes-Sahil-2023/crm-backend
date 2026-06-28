<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;
use Config\Database;
use Throwable;

/**
 * Call Tracker data — real device call rows stored in the `calls` SQL table.
 * Tenant-scoped (JWT routes the DB) and per-user (a user sees their own calls).
 * The companion mobile app POSTs calls here; the web app fetches + represents
 * them (matching to CRM leads happens client-side). Auth required (see Filters).
 */
class Calls extends ResourceController
{
    protected $format = 'json';

    /** GET /api/calls — the caller's calls, newest first. */
    public function index()
    {
        $me = $this->me();
        if ($me === null) {
            return $this->failUnauthorized('Not authenticated.');
        }
        try {
            $rows = Database::connect()->table('calls')
                ->where('user_id', $me)
                ->orderBy('called_at', 'DESC')
                ->orderBy('id', 'DESC')
                ->get()->getResultArray();
        } catch (Throwable $e) {
            return $this->fail('Call tracker is unavailable. The calls table may not be migrated yet.', 500);
        }

        return $this->respond(['calls' => array_map([$this, 'shape'], $rows)]);
    }

    /** POST /api/calls — log one call (from the device bridge or a manual entry). */
    public function create()
    {
        $me = $this->me();
        if ($me === null) {
            return $this->failUnauthorized('Not authenticated.');
        }
        $in     = $this->request->getJSON(true) ?? [];
        $number = trim((string) ($in['number'] ?? ''));
        $dir    = (string) ($in['direction'] ?? '');
        if ($number === '' || ! in_array($dir, ['incoming', 'outgoing', 'missed'], true)) {
            return $this->failValidationErrors('A number and a valid direction (incoming|outgoing|missed) are required.');
        }
        $at = trim((string) ($in['at'] ?? ''));
        $at = $at !== '' ? date('Y-m-d H:i:s', strtotime($at)) : date('Y-m-d H:i:s');

        try {
            $db  = Database::connect();
            $now = date('Y-m-d H:i:s');
            $db->table('calls')->insert([
                'user_id'      => $me,
                'number'       => substr($number, 0, 32),
                'raw_name'     => substr((string) ($in['rawName'] ?? ''), 0, 128) ?: null,
                'direction'    => $dir,
                'duration_sec' => max(0, (int) ($in['durationSec'] ?? 0)),
                'device'       => substr((string) ($in['device'] ?? ''), 0, 32) ?: null,
                'called_at'    => $at,
                'created_at'   => $now,
            ]);
            $row = $db->table('calls')->where('id', (int) $db->insertID())->get()->getRowArray();
        } catch (Throwable $e) {
            return $this->fail('Could not save the call. The calls table may not be migrated yet.', 500);
        }

        return $this->respondCreated(['call' => $this->shape($row)]);
    }

    /** DELETE /api/calls/{id} — remove one of the caller's calls. */
    public function delete($id = null)
    {
        $me = $this->me();
        if ($me === null) {
            return $this->failUnauthorized('Not authenticated.');
        }
        try {
            Database::connect()->table('calls')->where('id', (int) $id)->where('user_id', $me)->delete();
        } catch (Throwable $e) {
            // table missing — nothing to remove
        }

        return $this->respondDeleted(['id' => (int) $id]);
    }

    /** Map a DB row to the front-end Call shape. */
    private function shape(array $r): array
    {
        return [
            'id'          => (string) $r['id'],
            'number'      => (string) $r['number'],
            'rawName'     => (string) ($r['raw_name'] ?? ''),
            'direction'   => (string) $r['direction'],
            'durationSec' => (int) ($r['duration_sec'] ?? 0),
            'device'      => (string) ($r['device'] ?? ''),
            'at'          => $r['called_at'] ? date('c', strtotime((string) $r['called_at'])) : '',
        ];
    }

    private function me(): ?int
    {
        $id = $this->request->jwtUserId ?? null;

        return $id !== null && (int) $id > 0 ? (int) $id : null;
    }
}
