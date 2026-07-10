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

    /**
     * GET /api/calls/analytics — TEAM-WIDE call analytics for the Sales Call
     * Tracker dashboard, computed live from the `calls` table (no localStorage,
     * no seeded data). Aggregates across every user in the tenant and joins the
     * `users` roster for rep names + departments.
     *
     * Query: ?range=today|yesterday|7d|month|all (default: today).
     */
    public function analytics()
    {
        $me = $this->me();
        if ($me === null) {
            return $this->failUnauthorized('Not authenticated.');
        }

        $range = (string) ($this->request->getGet('range') ?? 'today');
        [$from, $to] = $this->rangeBounds($range);

        try {
            $db = Database::connect();

            // Reusable WHERE clause for the selected range.
            $scoped = static function ($builder) use ($from, $to) {
                if ($from !== null) {
                    $builder->where('called_at >=', $from);
                }
                if ($to !== null) {
                    $builder->where('called_at <', $to);
                }
                return $builder;
            };

            // Per-rep aggregates joined to the users roster for names/departments.
            $repRows = $scoped(
                $db->table('calls c')
                    ->select('c.user_id AS user_id')
                    ->select('COUNT(*) AS calls', false)
                    ->select('COUNT(DISTINCT c.number) AS uniq', false)
                    ->select("SUM(CASE WHEN c.direction <> 'missed' THEN 1 ELSE 0 END) AS connected", false)
                    ->select("SUM(CASE WHEN c.direction = 'missed' THEN 1 ELSE 0 END) AS missed", false)
                    ->select('SUM(c.duration_sec) AS talk_sec', false)
                    ->groupBy('c.user_id')
            )->get()->getResultArray();

            // Roster: id → name/department (only active login accounts).
            $users = [];
            foreach ($db->table('users')->select('id, name, department')->get()->getResultArray() as $u) {
                $users[(int) $u['id']] = $u;
            }

            $reps = [];
            foreach ($repRows as $r) {
                $uid       = (int) $r['user_id'];
                $calls     = (int) $r['calls'];
                $connected = (int) $r['connected'];
                $talkSec   = (int) $r['talk_sec'];
                $reps[] = [
                    'id'         => $uid,
                    'name'       => $users[$uid]['name'] ?? 'Unknown',
                    'dept'       => $users[$uid]['department'] ?? '—',
                    'calls'      => $calls,
                    'unique'     => (int) $r['uniq'],
                    'connected'  => $connected,
                    'missed'     => (int) $r['missed'],
                    'talkSec'    => $talkSec,
                    'avgSec'     => $connected > 0 ? (int) round($talkSec / $connected) : 0,
                    'connectPct' => $calls > 0 ? (int) round(($connected / $calls) * 100) : 0,
                ];
            }
            usort($reps, static fn ($a, $b) => $b['calls'] <=> $a['calls']);

            // Hourly distribution (0–23) across the whole team.
            $hourly = array_fill(0, 24, 0);
            $hourRows = $scoped(
                $db->table('calls')->select('HOUR(called_at) AS h, COUNT(*) AS c', false)->groupBy('h', false)
            )->get()->getResultArray();
            foreach ($hourRows as $h) {
                $hourly[(int) $h['h']] = (int) $h['c'];
            }

            // Direction breakdown.
            $direction = ['incoming' => 0, 'outgoing' => 0, 'missed' => 0];
            $dirRows = $scoped(
                $db->table('calls')->select('direction, COUNT(*) AS c', false)->groupBy('direction')
            )->get()->getResultArray();
            foreach ($dirRows as $d) {
                if (isset($direction[$d['direction']])) {
                    $direction[$d['direction']] = (int) $d['c'];
                }
            }

            // 7-day trend (independent of the selected range).
            $trendFrom = date('Y-m-d 00:00:00', strtotime('-6 days'));
            $trendRows = $db->table('calls')
                ->select('DATE(called_at) AS d, COUNT(*) AS c, AVG(duration_sec) AS avg', false)
                ->where('called_at >=', $trendFrom)
                ->groupBy('d', false)
                ->get()->getResultArray();
            $trendMap = [];
            foreach ($trendRows as $t) {
                $trendMap[$t['d']] = ['c' => (int) $t['c'], 'avg' => (int) round((float) $t['avg'])];
            }
            $trend = [];
            for ($i = 6; $i >= 0; $i--) {
                $day = date('Y-m-d', strtotime("-{$i} days"));
                $trend[] = [
                    'date'   => date('M j', strtotime($day)),
                    'calls'  => $trendMap[$day]['c'] ?? 0,
                    'avgSec' => $trendMap[$day]['avg'] ?? 0,
                ];
            }
        } catch (Throwable $e) {
            return $this->fail('Call analytics are unavailable. The calls table may not be migrated yet.', 500);
        }

        $totalCalls     = array_sum(array_column($reps, 'calls'));
        $totalUnique    = array_sum(array_column($reps, 'unique'));
        $totalConnected = array_sum(array_column($reps, 'connected'));
        $totalMissed    = array_sum(array_column($reps, 'missed'));
        $totalTalkSec   = array_sum(array_column($reps, 'talkSec'));

        return $this->respond([
            'range'  => $range,
            'totals' => [
                'calls'       => $totalCalls,
                'unique'      => $totalUnique,
                'connected'   => $totalConnected,
                'missed'      => $totalMissed,
                'talkSec'     => $totalTalkSec,
                'avgSec'      => $totalConnected > 0 ? (int) round($totalTalkSec / $totalConnected) : 0,
                'connectRate' => $totalCalls > 0 ? (int) round(($totalConnected / $totalCalls) * 100) : 0,
            ],
            'reps'      => $reps,
            'hourly'    => $hourly,
            'direction' => $direction,
            'trend'     => $trend,
        ]);
    }

    /** Resolve a range keyword to [from, to) datetime bounds (null = unbounded). */
    private function rangeBounds(string $range): array
    {
        switch ($range) {
            case 'yesterday':
                return [date('Y-m-d 00:00:00', strtotime('-1 day')), date('Y-m-d 00:00:00')];
            case '7d':
                return [date('Y-m-d 00:00:00', strtotime('-6 days')), null];
            case 'month':
                return [date('Y-m-01 00:00:00'), null];
            case 'all':
                return [null, null];
            case 'today':
            default:
                return [date('Y-m-d 00:00:00'), null];
        }
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
