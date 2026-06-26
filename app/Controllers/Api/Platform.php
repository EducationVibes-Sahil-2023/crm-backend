<?php

namespace App\Controllers\Api;

use App\Libraries\Jwt;
use App\Libraries\Settings;
use App\Models\UserModel;
use CodeIgniter\RESTful\ResourceController;

/**
 * Platform-owner configuration, stored in the main DB `settings` table (JSON):
 *   - platform.config : branding, landing content, plans, permissions, reviews,
 *                       payments, Google, automation (the Super Admin → Settings).
 *   - platform.demos  : demos booked from the public landing page.
 *
 * Reads of the config are PUBLIC (the landing page needs branding); writes and
 * demo management require the platform super-admin / an Administrator.
 */
class Platform extends ResourceController
{
    protected $format = 'json';

    private const CONFIG_KEY = 'platform.config';
    private const DEMOS_KEY  = 'platform.demos';

    /** GET /api/platform — public. The saved config (or {} so the client merges defaults). */
    public function index()
    {
        return $this->respond(['config' => Settings::get(self::CONFIG_KEY) ?? (object) []]);
    }

    /** POST /api/platform — super-admin. Persist the full platform config. */
    public function save()
    {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        $in = $this->request->getJSON(true);
        if (! is_array($in)) {
            return $this->failValidationErrors('A config object is required.');
        }
        // Accept either { config: {...} } or the raw config object.
        $cfg = (isset($in['config']) && is_array($in['config'])) ? $in['config'] : $in;
        Settings::set(self::CONFIG_KEY, $cfg);

        return $this->respond(['saved' => true]);
    }

    /** GET /api/platform/demos — super-admin. List demos booked from the landing page. */
    public function demos()
    {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }

        return $this->respond(['demos' => $this->loadDemos()]);
    }

    /** POST /api/platform/demos/book — public. Append a demo booked from the landing page. */
    public function bookDemo()
    {
        $in = $this->request->getJSON(true) ?? [];
        if (! is_array($in) || trim((string) ($in['name'] ?? '')) === '') {
            return $this->failValidationErrors('A demo with at least a name is required.');
        }
        $list   = $this->loadDemos();
        $list[] = $in;
        Settings::set(self::DEMOS_KEY, ['items' => $list]);

        return $this->respondCreated(['booked' => true]);
    }

    /** POST /api/platform/demos — super-admin. Replace the demos list (cancel/update). */
    public function saveDemos()
    {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        $in    = $this->request->getJSON(true) ?? [];
        $items = is_array($in['demos'] ?? null) ? $in['demos'] : (is_array($in) ? $in : []);
        Settings::set(self::DEMOS_KEY, ['items' => array_values($items)]);

        return $this->respond(['saved' => true]);
    }

    /** @return list<array<string,mixed>> */
    private function loadDemos(): array
    {
        $stored = Settings::get(self::DEMOS_KEY);
        $items  = $stored['items'] ?? null;

        return is_array($items) ? array_values($items) : [];
    }

    /**
     * Allow the platform super-admin (console JWT, role: super-admin) or a CRM
     * Administrator. Returns an error Response when unauthorized, else null.
     */
    private function requireAdmin()
    {
        $header = $this->request->getHeaderLine('Authorization');
        $token  = preg_match('/Bearer\s+(.+)/i', $header, $m) ? trim($m[1]) : '';
        $claims = $token !== '' ? Jwt::decode($token) : null;

        if ($claims === null) {
            return $this->failUnauthorized('Not authenticated.');
        }
        if (($claims['role'] ?? '') === 'super-admin') {
            return null;
        }
        $me = (new UserModel())->find((int) ($claims['sub'] ?? 0));
        if ($me !== null && strtolower((string) ($me['role'] ?? '')) === 'administrator') {
            return null;
        }

        return $this->failForbidden('Only the platform admin can change platform settings.');
    }
}
