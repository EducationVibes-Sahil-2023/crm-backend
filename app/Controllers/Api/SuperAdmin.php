<?php

namespace App\Controllers\Api;

use App\Libraries\Jwt;
use App\Libraries\Settings;
use CodeIgniter\RESTful\ResourceController;

/**
 * Super-admin (platform owner) authentication + JWT bridge.
 *
 * Credentials are stored in the DB (`settings` table, key `superadmin`) so they
 * can be changed at runtime from the console — no rebuild/redeploy. The .env
 * `superadmin.*` values are only a bootstrap fallback for a fresh install that
 * hasn't set DB credentials yet. The password is stored hashed.
 *
 * The console authenticates here (server-side) and caches the returned JWT
 * (sub: "super-admin"), which the protected APIs (Gmail, provisioning, platform
 * config) accept — so the console reuses them with isolated per-user storage.
 */
class SuperAdmin extends ResourceController
{
    protected $format = 'json';

    public const SUBJECT = 'super-admin';

    /**
     * POST /api/super-admin/login  { email, password }
     *   -> { ok, token, email, name }
     * The single source of truth for super-admin auth. The frontend stores the
     * returned token; nothing about the password is baked into the bundle.
     */
    public function login()
    {
        $in = $this->request->getJSON(true) ?: [];
        if (! $this->valid((string) ($in['email'] ?? ''), (string) ($in['password'] ?? ''))) {
            return $this->failUnauthorized('Invalid super admin credentials.');
        }
        $e = $this->expected();

        return $this->respond([
            'ok'    => true,
            'token' => Jwt::encode(['sub' => self::SUBJECT, 'role' => 'super-admin']),
            'email' => $e['email'],
            'name'  => $e['name'],
        ]);
    }

    /**
     * POST /api/super-admin/token  { email, password } -> { token }
     * Back-compat alias for older clients that mint a JWT directly.
     */
    public function token()
    {
        $in = $this->request->getJSON(true) ?: [];
        if (! $this->valid((string) ($in['email'] ?? ''), (string) ($in['password'] ?? ''))) {
            return $this->failUnauthorized('Invalid super admin credentials.');
        }

        return $this->respond(['token' => Jwt::encode(['sub' => self::SUBJECT, 'role' => 'super-admin'])]);
    }

    /**
     * POST /api/super-admin/credentials
     *   { currentPassword, email?, password?, name? }
     * Persist new super-admin credentials to the DB. Requires a valid super-admin
     * JWT *and* the current password (re-auth), so a stolen token alone can't
     * change the login. Password is hashed before storage.
     */
    public function credentials()
    {
        $header = $this->request->getHeaderLine('Authorization');
        $tok    = preg_match('/Bearer\s+(.+)/i', $header, $m) ? trim($m[1]) : '';
        $claims = $tok !== '' ? Jwt::decode($tok) : null;
        if (($claims['role'] ?? '') !== 'super-admin') {
            return $this->failUnauthorized('Super admin authentication required.');
        }

        $in  = $this->request->getJSON(true) ?: [];
        $cur = $this->expected();

        // Re-authenticate with the current password before allowing a change.
        if (! $this->valid($cur['email'], (string) ($in['currentPassword'] ?? ''))) {
            return $this->failUnauthorized('Current password is incorrect.');
        }

        $newEmail = strtolower(trim((string) ($in['email'] ?? ''))) ?: $cur['email'];
        $newName  = trim((string) ($in['name'] ?? '')) ?: $cur['name'];
        $newPass  = (string) ($in['password'] ?? '');

        // Keep the existing hash if no new password was supplied.
        $hash = $newPass !== ''
            ? password_hash($newPass, PASSWORD_DEFAULT)
            : ($cur['passwordHash'] !== '' ? $cur['passwordHash'] : password_hash($cur['envPassword'], PASSWORD_DEFAULT));

        Settings::set('superadmin', [
            'email'        => $newEmail,
            'name'         => $newName,
            'passwordHash' => $hash,
        ]);

        return $this->respond(['ok' => true, 'email' => $newEmail, 'name' => $newName]);
    }

    // ---- helpers ----

    /**
     * Resolve the expected super-admin identity: DB (`settings.superadmin`) first,
     * with the .env values as a bootstrap fallback when the DB has none yet.
     *
     * @return array{email:string,name:string,passwordHash:string,envPassword:string}
     */
    private function expected(): array
    {
        $db = Settings::get('superadmin') ?: [];

        return [
            'email'        => strtolower(trim((string) ($db['email'] ?? (env('superadmin.email') ?: 'superadmin@crm-cloud.app')))),
            'name'         => (string) ($db['name'] ?? (env('superadmin.name') ?: 'Platform Owner')),
            'passwordHash' => (string) ($db['passwordHash'] ?? ''),
            'envPassword'  => (string) (env('superadmin.password') ?: 'super123'),
        ];
    }

    /** Constant-time credential check: email match + hashed (DB) or .env password. */
    private function valid(string $email, string $password): bool
    {
        $e = $this->expected();
        if (! hash_equals($e['email'], strtolower(trim($email)))) {
            return false;
        }
        if ($e['passwordHash'] !== '') {
            return password_verify($password, $e['passwordHash']);
        }

        return hash_equals($e['envPassword'], $password);
    }
}
