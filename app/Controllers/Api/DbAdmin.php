<?php

namespace App\Controllers\Api;

use App\Libraries\Jwt;
use App\Libraries\Settings;
use App\Models\UserModel;
use CodeIgniter\RESTful\ResourceController;
use Config\Database;

/**
 * Super-admin database inspector + backup.
 *
 * Read-only introspection of the main database and every isolated client
 * database (tenant_*): table list with row counts/sizes, column structure,
 * indexes and paginated data. Plus mysqldump-based backups and a schedule.
 *
 * Every endpoint is gated to the platform admin (super-admin JWT).
 */
class DbAdmin extends ResourceController
{
    protected $format = 'json';

    // ---------------- introspection ----------------

    /** GET /api/db/databases -> [{ database, isMain }] (main + tenant_*). */
    public function databases()
    {
        if (($g = $this->requireAdmin()) !== null) {
            return $g;
        }
        $main = $this->mainDbName();
        $db   = Database::connect();
        $rows = $db->query("SHOW DATABASES LIKE 'tenant\\_%'")->getResultArray();
        $out  = [['database' => $main, 'isMain' => true]];
        foreach ($rows as $r) {
            $out[] = ['database' => array_values($r)[0], 'isMain' => false];
        }

        return $this->respond(['databases' => $out]);
    }

    /** GET /api/db/overview?db=X -> totals + table list (rows/size/engine). */
    public function overview()
    {
        if (($g = $this->requireAdmin()) !== null) {
            return $g;
        }
        $dbName = (string) $this->request->getGet('db');
        if (! $this->allowedDb($dbName)) {
            return $this->failValidationErrors('Unknown database.');
        }
        $db     = Database::connect();
        $tables = $db->query(
            'SELECT TABLE_NAME AS name, TABLE_ROWS AS rows_est, DATA_LENGTH + INDEX_LENGTH AS bytes, ENGINE AS engine
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = "BASE TABLE"
             ORDER BY TABLE_NAME',
            [$dbName],
        )->getResultArray();

        $list = array_map(static fn ($t) => [
            'name'   => $t['name'],
            'rows'   => (int) $t['rows_est'],
            'bytes'  => (int) $t['bytes'],
            'engine' => $t['engine'],
        ], $tables);

        return $this->respond([
            'database'   => $dbName,
            'tableCount' => count($list),
            'totalRows'  => array_sum(array_column($list, 'rows')),
            'totalBytes' => array_sum(array_column($list, 'bytes')),
            'tables'     => $list,
        ]);
    }

    /** GET /api/db/table?db=X&table=Y -> columns + indexes. */
    public function table()
    {
        if (($g = $this->requireAdmin()) !== null) {
            return $g;
        }
        $dbName = (string) $this->request->getGet('db');
        $table  = (string) $this->request->getGet('table');
        if (! $this->allowedDb($dbName) || ! $this->tableExists($dbName, $table)) {
            return $this->failValidationErrors('Unknown database or table.');
        }
        $db = Database::connect();

        $columns = $db->query(
            'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable,
                    COLUMN_KEY AS `key`, COLUMN_DEFAULT AS `default`, EXTRA AS extra
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION',
            [$dbName, $table],
        )->getResultArray();

        $stats = $db->query(
            'SELECT INDEX_NAME AS name, COLUMN_NAME AS col, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS seq
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$dbName, $table],
        )->getResultArray();

        $byName = [];
        foreach ($stats as $s) {
            $byName[$s['name']] ??= ['name' => $s['name'], 'unique' => ((int) $s['non_unique']) === 0, 'columns' => []];
            $byName[$s['name']]['columns'][] = $s['col'];
        }

        return $this->respond([
            'columns' => array_map(static fn ($c) => [
                'name'     => $c['name'],
                'type'     => $c['type'],
                'nullable' => $c['nullable'] === 'YES',
                'key'      => $c['key'],
                'default'  => $c['default'],
                'extra'    => $c['extra'],
            ], $columns),
            'indexes' => array_values($byName),
        ]);
    }

    /** GET /api/db/data?db=X&table=Y&limit=&offset= -> columns + rows + total. */
    public function data()
    {
        if (($g = $this->requireAdmin()) !== null) {
            return $g;
        }
        $dbName = (string) $this->request->getGet('db');
        $table  = (string) $this->request->getGet('table');
        if (! $this->allowedDb($dbName) || ! $this->tableExists($dbName, $table)) {
            return $this->failValidationErrors('Unknown database or table.');
        }
        $limit  = max(1, min(200, (int) ($this->request->getGet('limit') ?: 50)));
        $offset = max(0, (int) $this->request->getGet('offset'));

        $conn  = $this->dbFor($dbName);
        $total = (int) ($conn->query("SELECT COUNT(*) AS c FROM `{$table}`")->getRowArray()['c'] ?? 0);
        $rows  = $conn->query("SELECT * FROM `{$table}` LIMIT {$limit} OFFSET {$offset}")->getResultArray();
        $cols  = $rows ? array_keys($rows[0]) : [];
        if (! $cols) {
            $c    = $conn->query("SHOW COLUMNS FROM `{$table}`")->getResultArray();
            $cols = array_column($c, 'Field');
        }

        return $this->respond(['columns' => $cols, 'rows' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
    }

    // ---------------- backups ----------------

    /** GET /api/db/schedule -> saved backup schedule. */
    public function getSchedule()
    {
        if (($g = $this->requireAdmin()) !== null) {
            return $g;
        }
        $s = Settings::get('backup_schedule') ?: [];

        return $this->respond([
            'enabled'   => (bool) ($s['enabled'] ?? true),
            'frequency' => (string) ($s['frequency'] ?? 'daily'),
            'keepDays'  => (int) ($s['keepDays'] ?? 14),
            'scope'     => (string) ($s['scope'] ?? 'all'),
            'lastRunAt' => $s['lastRunAt'] ?? null,
        ]);
    }

    /** POST /api/db/schedule -> persist the backup schedule. */
    public function saveSchedule()
    {
        if (($g = $this->requireAdmin()) !== null) {
            return $g;
        }
        $in = $this->request->getJSON(true) ?: [];
        Settings::set('backup_schedule', [
            'enabled'   => (bool) ($in['enabled'] ?? true),
            'frequency' => in_array($in['frequency'] ?? '', ['hourly', 'daily', 'weekly'], true) ? $in['frequency'] : 'daily',
            'keepDays'  => max(1, min(365, (int) ($in['keepDays'] ?? 14))),
            'scope'     => in_array($in['scope'] ?? '', ['main', 'all', 'client'], true) ? $in['scope'] : 'all',
            'lastRunAt' => (Settings::get('backup_schedule')['lastRunAt'] ?? null),
        ]);

        return $this->respond(['ok' => true]);
    }

    /** GET /api/db/backups -> stored .sql backups on disk. */
    public function backups()
    {
        if (($g = $this->requireAdmin()) !== null) {
            return $g;
        }

        return $this->respond(['backups' => $this->listBackups()]);
    }

    /** POST /api/db/backup { scope, db? } -> run mysqldump for the target(s). */
    public function runBackup()
    {
        if (($g = $this->requireAdmin()) !== null) {
            return $g;
        }
        $in    = $this->request->getJSON(true) ?: [];
        $scope = (string) ($in['scope'] ?? 'main');

        $targets = [];
        if ($scope === 'client' && $this->allowedDb((string) ($in['db'] ?? ''))) {
            $targets = [$in['db']];
        } elseif ($scope === 'all') {
            $targets = array_column($this->respondDatabasesList(), 'database');
        } else {
            $targets = [$this->mainDbName()];
        }

        $done = [];
        $err  = null;
        foreach ($targets as $t) {
            $res = $this->dumpDatabase($t);
            if ($res['ok']) {
                $done[] = $res['file'];
            } else {
                $err = $res['error'];
            }
        }

        // Prune old backups + stamp the run.
        $this->pruneBackups();
        $s = Settings::get('backup_schedule') ?: [];
        $s['lastRunAt'] = date('c');
        Settings::set('backup_schedule', $s);

        if (! $done && $err) {
            return $this->fail(['error' => $err]);
        }

        return $this->respond(['ok' => true, 'files' => $done, 'backups' => $this->listBackups(), 'warning' => $err]);
    }

    // ---------------- helpers ----------------

    private function mainDbName(): string
    {
        return (string) ((new Database())->default['database'] ?? '');
    }

    private function dbFor(string $dbName): \CodeIgniter\Database\BaseConnection
    {
        if ($dbName === $this->mainDbName()) {
            return Database::connect();
        }
        $cfg             = (array) (new Database())->default;
        $cfg['database'] = $dbName;
        $cfg['DBDebug']  = false;

        return Database::connect($cfg, false);
    }

    private function allowedDb(string $dbName): bool
    {
        return $dbName !== '' && ($dbName === $this->mainDbName() || preg_match('/^tenant_[a-z0-9_]+$/', $dbName) === 1);
    }

    private function tableExists(string $dbName, string $table): bool
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            return false;
        }
        $n = (int) (Database::connect()->query(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$dbName, $table],
        )->getRowArray()['c'] ?? 0);

        return $n > 0;
    }

    /** @return list<array{database:string,isMain:bool}> */
    private function respondDatabasesList(): array
    {
        $rows = Database::connect()->query("SHOW DATABASES LIKE 'tenant\\_%'")->getResultArray();
        $out  = [['database' => $this->mainDbName(), 'isMain' => true]];
        foreach ($rows as $r) {
            $out[] = ['database' => array_values($r)[0], 'isMain' => false];
        }

        return $out;
    }

    private function backupDir(): string
    {
        $dir = WRITEPATH . 'backups/';
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /** @return list<array{name:string,bytes:int,at:string,database:string}> */
    private function listBackups(): array
    {
        $dir   = $this->backupDir();
        $files = glob($dir . '*.sql') ?: [];
        $out   = [];
        foreach ($files as $f) {
            $base = basename($f);
            $out[] = [
                'name'     => $base,
                'bytes'    => (int) filesize($f),
                'at'       => date('c', (int) filemtime($f)),
                'database' => preg_replace('/-\d{8}-\d{6}\.sql$/', '', $base) ?: $base,
            ];
        }
        usort($out, static fn ($a, $b) => strcmp($b['at'], $a['at']));

        return $out;
    }

    /** @return array{ok:bool,file?:string,error?:string} */
    private function dumpDatabase(string $dbName): array
    {
        if (! function_exists('exec')) {
            return ['ok' => false, 'error' => 'PHP exec() is disabled — cannot run mysqldump.'];
        }
        $cfg  = (array) (new Database())->default;
        $bin  = $this->mysqldumpBin();
        if ($bin === null) {
            return ['ok' => false, 'error' => 'mysqldump not found on the server.'];
        }
        $file = $this->backupDir() . $dbName . '-' . date('Ymd-His') . '.sql';
        $cmd  = sprintf(
            '%s --host=%s --port=%s --user=%s %s --single-transaction --skip-lock-tables %s > %s 2>&1',
            escapeshellarg($bin),
            escapeshellarg((string) ($cfg['hostname'] ?? '127.0.0.1')),
            escapeshellarg((string) ($cfg['port'] ?? 3306)),
            escapeshellarg((string) ($cfg['username'] ?? 'root')),
            ($cfg['password'] ?? '') !== '' ? '--password=' . escapeshellarg((string) $cfg['password']) : '',
            escapeshellarg($dbName),
            escapeshellarg($file),
        );
        @exec($cmd, $o, $code);
        if ($code !== 0 || ! is_file($file) || filesize($file) === 0) {
            @unlink($file);

            return ['ok' => false, 'error' => 'mysqldump failed for ' . $dbName . '.'];
        }

        return ['ok' => true, 'file' => basename($file)];
    }

    private function mysqldumpBin(): ?string
    {
        foreach (['C:\\xampp\\mysql\\bin\\mysqldump.exe', '/usr/bin/mysqldump', '/usr/local/bin/mysqldump', 'mysqldump'] as $c) {
            if ($c === 'mysqldump' || is_file($c)) {
                return $c;
            }
        }

        return null;
    }

    private function pruneBackups(): void
    {
        $keep = (int) ((Settings::get('backup_schedule')['keepDays'] ?? 14));
        $cut  = time() - ($keep * 86400);
        foreach (glob($this->backupDir() . '*.sql') ?: [] as $f) {
            if ((int) filemtime($f) < $cut) {
                @unlink($f);
            }
        }
    }

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

        return $this->failForbidden('Only the platform admin can inspect databases.');
    }
}
