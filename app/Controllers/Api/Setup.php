<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;
use Config\Database as DatabaseConfig;
use Config\Database;
use Config\Services;
use mysqli;
use Throwable;

/**
 * Web-triggered deploy bootstrap — the no-CLI equivalent of `php spark setup`.
 *
 *   GET /api/setup?key=<setup.key>
 *
 * On shared hosting (no SSH / no spark CLI) this lets you build and UPDATE the
 * database structure by simply opening a URL — or by having CI/CD curl it after
 * each deploy. It is:
 *   1. Key-guarded   — refuses unless ?key matches env('setup.key').
 *   2. Idempotent    — safe to hit on every deploy; only pending migrations run.
 *   3. Self-healing  — creates the database if the DB user has the privilege,
 *                      otherwise reports it and continues against the existing DB.
 *
 * Steps: ensure database -> run all migrations -> seed the admin login.
 */
class Setup extends ResourceController
{
    protected $format = 'json';

    /** GET /api/setup?key=... */
    public function run()
    {
        // 1. Guard — a shared secret in .env. Without it (or on mismatch) refuse,
        //    so a public URL can't be used to hammer migrations/seeds.
        $key      = (string) ($this->request->getGet('key') ?? '');
        $expected = (string) (env('setup.key') ?: '');
        if ($expected === '' || ! hash_equals($expected, $key)) {
            return $this->failUnauthorized('Invalid or missing setup key.');
        }

        $steps = [];

        // 2. Ensure the database exists. Best-effort: many shared hosts give the
        //    DB user no server-level CREATE rights (the DB is pre-made in cPanel),
        //    so a failure here is fine — we just migrate into the existing DB.
        $cfg  = (new DatabaseConfig())->default;
        $name = (string) ($cfg['database'] ?? '');
        try {
            $mysqli = @new mysqli(
                (string) ($cfg['hostname'] ?? 'localhost'),
                (string) ($cfg['username'] ?? ''),
                (string) ($cfg['password'] ?? ''),
                '',
                (int) ($cfg['port'] ?? 3306),
            );
            if ($mysqli->connect_errno) {
                $steps['database'] = 'using existing (could not connect server-level: ' . $mysqli->connect_error . ')';
            } else {
                $safe    = str_replace('`', '', $name);
                $charset = $cfg['charset'] ?? 'utf8mb4';
                $collat  = $cfg['DBCollat'] ?? 'utf8mb4_general_ci';
                $ok      = $mysqli->query("CREATE DATABASE IF NOT EXISTS `{$safe}` CHARACTER SET {$charset} COLLATE {$collat}");
                $mysqli->close();
                $steps['database'] = $ok ? "ensured `{$name}`" : 'using existing (no CREATE privilege)';
            }
        } catch (Throwable $e) {
            $steps['database'] = 'using existing (' . $e->getMessage() . ')';
        }

        // 3. Build / update the schema — run every pending App migration. This is
        //    the auto-update: deploy new migration files, hit this URL, done.
        try {
            $migrate = Services::migrations();
            $migrate->setNamespace('App');
            $migrate->latest();
            $steps['migrations'] = 'applied (schema up to date)';
        } catch (Throwable $e) {
            return $this->fail([
                'ok'    => false,
                'steps' => $steps,
                'error' => 'Migration failed: ' . $e->getMessage(),
            ], 500);
        }

        // 4. Seed the admin login (idempotent upsert — admin@nexus.com / admin123).
        try {
            Database::seeder()->call('UserSeeder');
            $steps['seed'] = 'admin ensured (admin@nexus.com)';
        } catch (Throwable $e) {
            $steps['seed'] = 'skipped (' . $e->getMessage() . ')';
        }

        return $this->respond([
            'ok'       => true,
            'database' => $name,
            'steps'    => $steps,
        ]);
    }
}
