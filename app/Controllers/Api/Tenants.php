<?php

namespace App\Controllers\Api;

use App\Libraries\Jwt;
use App\Models\UserModel;
use CodeIgniter\RESTful\ResourceController;
use Config\Database;

/**
 * Multi-tenant provisioning. Each client workspace gets its own MySQL database
 * (`tenant_<slug>`) with its own `users` table + seeded admin login — so a
 * client's data is fully isolated from every other client.
 *
 * Admin-only (CRM Administrator JWT).
 */
class Tenants extends ResourceController
{
    protected $format = 'json';

    /**
     * GET /api/tenants — list provisioned client workspaces.
     * Returns the client registry (company, plan, admin, status) joined with the
     * live tenant databases + their user counts, so the Super Admin console shows
     * the real server-side clients (not just whatever is in browser storage).
     */
    public function index()
    {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        $db   = Database::connect();
        $rows = $db->query("SHOW DATABASES LIKE 'tenant\\_%'")->getResultArray();
        $dbs  = array_map(static fn ($r) => array_values($r)[0], $rows);

        // Live user count per existing tenant database.
        $counts = [];
        foreach ($dbs as $name) {
            try {
                $tdb           = $this->tenantDb($name);
                $counts[$name] = (int) ($tdb->query('SELECT COUNT(*) AS c FROM `users`')->getRowArray()['c'] ?? 0);
                $tdb->close();
            } catch (\Throwable $e) {
                $counts[$name] = 0; // table may not exist yet
            }
        }

        // Registry rows from the main DB (company / plan / admin / status / …).
        $registry = [];
        try {
            $registry = $db->query('SELECT * FROM `tenants` ORDER BY created_at DESC')->getResultArray();
        } catch (\Throwable $e) {
            // tenants registry table may not exist yet — fall back to raw DB list
        }

        $seen    = [];
        $clients = [];
        foreach ($registry as $r) {
            $name        = (string) $r['db_name'];
            $seen[$name] = true;
            $status      = (string) ($r['status'] ?? '') ?: ((int) ($r['active'] ?? 1) === 1 ? 'Active' : 'Suspended');
            $clients[]   = [
                'company'    => $r['company'],
                'slug'       => $r['slug'],
                'database'   => $name,
                'adminName'  => $r['admin_name'] ?? '',
                'adminEmail' => $r['admin_email'] ?? '',
                'plan'       => $r['plan'] ?? 'starter',
                'region'     => $r['region'] ?? '',
                'status'     => $status,
                'storageGb'  => (int) ($r['storage_gb'] ?? 0),
                'active'     => $status !== 'Suspended',
                'users'      => $counts[$name] ?? 0,
                'exists'     => in_array($name, $dbs, true),
                'createdAt'  => $r['created_at'] ?? null,
            ];
        }
        // Databases that exist but were never registered (created out-of-band).
        foreach ($dbs as $name) {
            if (! isset($seen[$name])) {
                $clients[] = [
                    'company'    => ucfirst(substr($name, 7)),
                    'slug'       => substr($name, 7),
                    'database'   => $name,
                    'adminName'  => '',
                    'adminEmail' => '',
                    'plan'       => 'starter',
                    'region'     => '',
                    'status'     => 'Active',
                    'storageGb'  => 0,
                    'active'     => true,
                    'users'      => $counts[$name] ?? 0,
                    'exists'     => true,
                    'createdAt'  => null,
                ];
            }
        }

        $out = array_map(static fn ($name) => ['database' => $name, 'users' => $counts[$name] ?? 0], $dbs);

        return $this->respond(['databases' => $out, 'clients' => $clients, 'count' => count($clients)]);
    }

    /**
     * POST /api/tenants/provision
     * { company, subdomain, adminName, adminEmail, password, plan? }
     * Creates the client's database, its users table, and the admin login.
     */
    public function provision()
    {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        $in = $this->request->getJSON(true) ?? [];

        $slug      = $this->slug((string) ($in['subdomain'] ?? $in['company'] ?? ''));
        $adminName = trim((string) ($in['adminName'] ?? 'Administrator'));
        $email     = strtolower(trim((string) ($in['adminEmail'] ?? '')));
        $password  = (string) ($in['password'] ?? '');

        if ($slug === '') {
            return $this->failValidationErrors('A subdomain or company name is required.');
        }
        if ($email === '' || strlen($password) < 6) {
            return $this->failValidationErrors('A client admin email and a password (min 6 chars) are required.');
        }

        $dbName    = $this->tenantDbName((string) ($in['dbName'] ?? ''), $slug);
        $company   = trim((string) ($in['company'] ?? $slug));
        $plan      = strtolower(trim((string) ($in['plan'] ?? 'starter'))) ?: 'starter';
        $region    = trim((string) ($in['region'] ?? ''));
        $status    = trim((string) ($in['status'] ?? 'Active')) ?: 'Active';
        $storageGb = (int) ($in['storageGb'] ?? 0);
        $root      = Database::connect();

        // 1. Create the isolated database (idempotent).
        $existed = (bool) $root->query("SHOW DATABASES LIKE " . $root->escape($dbName))->getRowArray();
        $root->query("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

        // 2. Build the full schema by running every migration inside the new DB,
        //    so the client gets all DB-backed module tables (blank). Falls back to
        //    just the users table if the migration runner can't target the group.
        $migrated = $this->migrateTenant($dbName);

        // 3. Seed the admin login inside that database.
        $tdb = $this->tenantDb($dbName);
        if (! $migrated) {
            $tdb->query($this->usersTableSql());
        }
        $seeded = false;
        $clash  = $tdb->query('SELECT id FROM `users` WHERE email = ' . $tdb->escape($email))->getRowArray();
        if ($clash === null) {
            $tdb->query(
                'INSERT INTO `users` (name, username, email, role, active, password, created_at, updated_at) '
                . 'VALUES (?, ?, ?, ?, 1, ?, NOW(), NOW())',
                [$adminName, $this->usernameFrom($tdb, $email), $email, 'Administrator', password_hash($password, PASSWORD_DEFAULT)],
            );
            $seeded = true;
        }
        $tdb->close();

        // 4. Record the client in the main-DB registry (company, plan, db, admin, …).
        $this->registerTenant($root, $company, $slug, $dbName, $email, $plan, $adminName, $region, $status, $storageGb);

        return $this->respondCreated([
            'database'       => $dbName,
            'dbHost'         => $root->hostname ?? 'localhost',
            'alreadyExisted' => $existed,
            'schemaMigrated' => $migrated,
            'adminSeeded'    => $seeded,
            'adminEmail'     => $email,
            'plan'           => $plan,
        ]);
    }

    /**
     * POST /api/tenants/impersonate { database | subdomain }
     * Super-admin only. Mints a CRM session token scoped to the client database
     * so the platform owner drops straight into that client's workspace.
     */
    public function impersonate()
    {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        $in     = $this->request->getJSON(true) ?? [];
        $dbName = trim((string) ($in['database'] ?? ''));
        if ($dbName === '' && ! empty($in['subdomain'])) {
            $dbName = 'tenant_' . $this->slug((string) $in['subdomain']);
        }
        if (! preg_match('/^tenant_[a-z0-9_]+$/', $dbName)) {
            return $this->failValidationErrors('A valid tenant database is required.');
        }

        try {
            $tdb   = $this->tenantDb($dbName);
            $admin = $tdb->query("SELECT * FROM `users` WHERE role = 'Administrator' AND active = 1 ORDER BY id ASC LIMIT 1")->getRowArray()
                ?: $tdb->query('SELECT * FROM `users` ORDER BY id ASC LIMIT 1')->getRowArray();
            $tdb->close();
        } catch (\Throwable $e) {
            return $this->fail('Could not open the client database.', 502);
        }
        if ($admin === null) {
            return $this->failNotFound('This client has no login accounts yet.');
        }

        // 8-hour impersonation session; the `tenant` claim routes every request.
        $token = Jwt::encode([
            'sub'    => (int) $admin['id'],
            'tenant' => $dbName,
            'role'   => $admin['role'] ?? 'Administrator',
            'name'   => $admin['name'] ?? '',
        ], 28800);

        return $this->respond([
            'token'        => $token,
            'impersonated' => true,
            'tenant'       => $dbName,
            'user'         => [
                'id'            => (int) $admin['id'],
                'name'          => $admin['name'] ?? '',
                'username'      => $admin['username'] ?? '',
                'email'         => $admin['email'] ?? '',
                'role'          => $admin['role'] ?? 'Administrator',
                'active'        => (bool) ($admin['active'] ?? 1),
                'twofa_enabled' => (bool) ($admin['twofa_enabled'] ?? 0),
            ],
        ]);
    }

    /**
     * POST /api/tenants/reset-password
     * { database | subdomain, password, adminEmail? }
     * Super-admin recovery: resets a client admin's login password inside the
     * tenant database and re-activates the account, so the platform owner can
     * restore access for a locked-out client. Targets the given adminEmail, else
     * the client's primary Administrator account.
     */
    public function resetPassword()
    {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        $in     = $this->request->getJSON(true) ?? [];
        $dbName = trim((string) ($in['database'] ?? ''));
        if ($dbName === '' && ! empty($in['subdomain'])) {
            $dbName = 'tenant_' . $this->slug((string) $in['subdomain']);
        }
        if (! preg_match('/^tenant_[a-z0-9_]+$/', $dbName)) {
            return $this->failValidationErrors('A valid tenant database is required.');
        }
        $password = (string) ($in['password'] ?? '');
        if (strlen($password) < 6) {
            return $this->failValidationErrors('A new password (min 6 chars) is required.');
        }
        $email = strtolower(trim((string) ($in['adminEmail'] ?? '')));

        try {
            $tdb = $this->tenantDb($dbName);
            // Target the requested admin email, else the primary Administrator
            // account (active ones first, lowest id) — matching impersonate().
            $admin = $email !== ''
                ? $tdb->query('SELECT * FROM `users` WHERE email = ' . $tdb->escape($email) . ' LIMIT 1')->getRowArray()
                : ($tdb->query("SELECT * FROM `users` WHERE role = 'Administrator' ORDER BY active DESC, id ASC LIMIT 1")->getRowArray()
                    ?: $tdb->query('SELECT * FROM `users` ORDER BY id ASC LIMIT 1')->getRowArray());
            if ($admin === null) {
                $tdb->close();

                return $this->failNotFound('This client has no matching login account.');
            }
            $tdb->query(
                'UPDATE `users` SET password = ?, active = 1, updated_at = NOW() WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), (int) $admin['id']],
            );
            $tdb->close();
        } catch (\Throwable $e) {
            return $this->fail('Could not reset the client password.', 502);
        }

        return $this->respond([
            'reset'      => true,
            'database'   => $dbName,
            'adminEmail' => $admin['email'] ?? $email,
            'adminName'  => $admin['name'] ?? '',
        ]);
    }

    /** POST /api/tenants/drop  { subdomain | database } — deprovision a client database. */
    public function drop()
    {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        $in     = $this->request->getJSON(true) ?? [];
        $dbName = trim((string) ($in['database'] ?? ''));
        if ($dbName === '' && ! empty($in['subdomain'])) {
            $dbName = 'tenant_' . $this->slug((string) $in['subdomain']);
        }
        if (! preg_match('/^tenant_[a-z0-9_]+$/', $dbName)) {
            return $this->failValidationErrors('A valid tenant database name is required.');
        }
        $root = Database::connect();
        $root->query("DROP DATABASE IF EXISTS `{$dbName}`");
        // Remove the registry record too so the client disappears from the console.
        try {
            $root->query('DELETE FROM `tenants` WHERE db_name = ?', [$dbName]);
        } catch (\Throwable $e) {
            // registry table may not exist — ignore
        }

        return $this->respondDeleted(['database' => $dbName]);
    }

    // ---- helpers ----

    /**
     * Give the tenant database the full schema by replicating every table's
     * structure (blank) from the main database. Reliable and connection-safe —
     * no migration-runner group juggling. Platform-only tables are skipped.
     * Returns false on failure so the caller falls back to the users table.
     */
    private function migrateTenant(string $dbName): bool
    {
        $skip = ['tenants', 'migrations']; // main-DB-only (registry + migration log)
        try {
            $root = Database::connect();
            $tdb  = $this->tenantDb($dbName);
            foreach ($root->query('SHOW TABLES')->getResultArray() as $row) {
                $table = array_values($row)[0];
                if (in_array($table, $skip, true)) {
                    continue;
                }
                $create = $root->query("SHOW CREATE TABLE `{$table}`")->getRowArray();
                $sql    = (string) ($create['Create Table'] ?? '');
                if ($sql === '') {
                    continue;
                }
                // Copy the structure only (no rows) into the client DB.
                $sql = preg_replace('/^CREATE TABLE `' . preg_quote($table, '/') . '`/', "CREATE TABLE IF NOT EXISTS `{$table}`", $sql, 1);
                $tdb->query($sql);
            }
            $tdb->close();

            return true;
        } catch (\Throwable $e) {
            log_message('error', 'Tenant schema copy failed for {db}: {msg}', ['db' => $dbName, 'msg' => $e->getMessage()]);

            return false;
        }
    }

    /** Upsert the client into the main-DB tenant registry. */
    private function registerTenant(\CodeIgniter\Database\BaseConnection $root, string $company, string $slug, string $dbName, string $email, string $plan, string $adminName = '', string $region = '', string $status = 'Active', int $storageGb = 0): void
    {
        $active = $status === 'Suspended' ? 0 : 1;
        try {
            $exists = $root->query('SELECT id FROM `tenants` WHERE db_name = ' . $root->escape($dbName))->getRowArray();
            if ($exists) {
                $root->query(
                    'UPDATE `tenants` SET company = ?, slug = ?, admin_email = ?, plan = ?, active = ?, updated_at = NOW() WHERE db_name = ?',
                    [$company, $slug, $email, $plan, $active, $dbName],
                );
            } else {
                $root->query(
                    'INSERT INTO `tenants` (company, slug, db_name, admin_email, plan, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
                    [$company, $slug, $dbName, $email, $plan, $active],
                );
            }
            // Extended profile columns (added by a later migration) — best-effort so
            // provisioning still works if the migration hasn't been run yet.
            try {
                $root->query(
                    'UPDATE `tenants` SET admin_name = ?, region = ?, status = ?, storage_gb = ? WHERE db_name = ?',
                    [$adminName, $region, $status, $storageGb, $dbName],
                );
            } catch (\Throwable $e) {
                // columns not present yet — ignore
            }
        } catch (\Throwable $e) {
            log_message('error', 'Tenant registry write failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * POST /api/tenants/update
     * { database, company?, plan?, status?, adminName?, adminEmail?, region?, storageGb? }
     * Updates a client's registry record (no DB re-provision).
     */
    public function updateClient()
    {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        $in     = $this->request->getJSON(true) ?? [];
        $dbName = trim((string) ($in['database'] ?? ''));
        if (! preg_match('/^tenant_[a-z0-9_]+$/', $dbName)) {
            return $this->failValidationErrors('A valid tenant database is required.');
        }
        $root = Database::connect();
        $row  = $root->query('SELECT id FROM `tenants` WHERE db_name = ' . $root->escape($dbName))->getRowArray();
        if ($row === null) {
            return $this->failNotFound('No registry record for that client.');
        }

        // Build the SET clause only from provided fields.
        $map = [
            'company'     => 'company',
            'plan'        => 'plan',
            'status'      => 'status',
            'adminName'   => 'admin_name',
            'adminEmail'  => 'admin_email',
            'region'      => 'region',
            'storageGb'   => 'storage_gb',
        ];
        $sets = [];
        $args = [];
        foreach ($map as $key => $col) {
            if (array_key_exists($key, $in)) {
                $sets[] = "`{$col}` = ?";
                $args[] = $key === 'storageGb' ? (int) $in[$key] : (string) $in[$key];
            }
        }
        if (array_key_exists('status', $in)) {
            $sets[] = '`active` = ?';
            $args[] = ((string) $in['status']) === 'Suspended' ? 0 : 1;
        }
        if ($sets === []) {
            return $this->respond(['updated' => false, 'database' => $dbName]);
        }
        $sets[] = '`updated_at` = NOW()';
        $args[] = $dbName;

        try {
            $root->query('UPDATE `tenants` SET ' . implode(', ', $sets) . ' WHERE db_name = ?', $args);
        } catch (\Throwable $e) {
            return $this->fail('Could not update the client.', 500);
        }

        return $this->respond(['updated' => true, 'database' => $dbName]);
    }

    /** Open a connection to a specific tenant database (reuses default credentials). */
    private function tenantDb(string $dbName): \CodeIgniter\Database\BaseConnection
    {
        $cfg = (array) (new \Config\Database())->default;
        $cfg['database'] = $dbName;
        $cfg['DBDebug']  = false;

        return Database::connect($cfg, false);
    }

    private function usersTableSql(): string
    {
        return <<<SQL
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `username` VARCHAR(100) NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `role` VARCHAR(60) DEFAULT 'Member',
  `active` TINYINT(1) DEFAULT 1,
  `phone` VARCHAR(40) NULL,
  `department` VARCHAR(120) NULL,
  `designation` VARCHAR(120) NULL,
  `avatar` TEXT NULL,
  `twofa_enabled` TINYINT(1) DEFAULT 0,
  `twofa_secret` VARCHAR(64) NULL,
  `password` VARCHAR(255) NOT NULL,
  `api_token` VARCHAR(64) NULL,
  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email` (`email`),
  UNIQUE KEY `users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    }

    private function usernameFrom(\CodeIgniter\Database\BaseConnection $db, string $email): string
    {
        $base = preg_replace('/[^a-z0-9._-]/', '', strtolower(explode('@', $email)[0] ?? 'admin')) ?: 'admin';
        $name = $base;
        $i    = 1;
        while ($db->query('SELECT id FROM `users` WHERE username = ' . $db->escape($name))->getRowArray() !== null) {
            $name = $base . $i++;
        }

        return $name;
    }

    private function slug(string $s): string
    {
        return substr(preg_replace('/[^a-z0-9]/', '', strtolower($s)), 0, 32);
    }

    /**
     * Resolve the tenant database name. Honours a caller-supplied override but
     * always enforces the mandatory `tenant_` prefix and a safe identifier
     * (`[a-z0-9_]`), so DB listing and the impersonate/drop guards keep working.
     * Falls back to `tenant_<slug>` when no valid override is given.
     */
    private function tenantDbName(string $requested, string $slug): string
    {
        $suffix = preg_replace('/[^a-z0-9_]/', '', preg_replace('/^tenant_/', '', strtolower(trim($requested))));
        $suffix = substr((string) $suffix, 0, 40);

        return 'tenant_' . ($suffix !== '' ? $suffix : $slug);
    }

    /**
     * Returns an error Response unless the caller is the platform super-admin or
     * a CRM Administrator, else null. Provisioning is a platform-owner action, so
     * the super-admin console's JWT (role: super-admin) is accepted directly.
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

        return $this->failForbidden('Only the platform admin can provision client databases.');
    }
}
