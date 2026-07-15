<?php

namespace App\Commands;

use App\Libraries\Settings;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * Scheduled database backup — put this in cron (e.g. `0 2 * * *`); the saved
 * schedule (Super Admin → Database) decides whether it actually runs and what
 * to back up. Dumps to writable/backups and prunes files older than keepDays.
 */
class BackupRun extends BaseCommand
{
    protected $group       = 'Database';
    protected $name        = 'backup:run';
    protected $description = 'Run scheduled database backups (main + client DBs) per the saved schedule.';

    public function run(array $params)
    {
        $s = Settings::get('backup_schedule') ?: [];
        if (isset($s['enabled']) && ! $s['enabled']) {
            CLI::write('Backups are disabled in the schedule. Nothing to do.', 'yellow');

            return;
        }
        $scope   = (string) ($s['scope'] ?? 'all');
        $keep    = max(1, (int) ($s['keepDays'] ?? 14));
        $targets = $scope === 'main' ? [$this->mainDb()] : $this->allDatabases();

        $ok = 0;
        foreach ($targets as $db) {
            if ($this->dump($db)) {
                CLI::write("  ✓ backed up {$db}", 'green');
                $ok++;
            } else {
                CLI::write("  ✗ failed {$db}", 'red');
            }
        }
        $this->prune($keep);

        $s['lastRunAt'] = date('c');
        Settings::set('backup_schedule', $s);
        CLI::write("Done — {$ok}/" . count($targets) . ' database(s) backed up.', 'green');
    }

    private function mainDb(): string
    {
        return (string) ((new Database())->default['database'] ?? '');
    }

    /** @return list<string> */
    private function allDatabases(): array
    {
        $rows = Database::connect()->query("SHOW DATABASES LIKE 'tenant\\_%'")->getResultArray();
        $out  = [$this->mainDb()];
        foreach ($rows as $r) {
            $out[] = array_values($r)[0];
        }

        return $out;
    }

    private function dir(): string
    {
        $d = WRITEPATH . 'backups/';
        if (! is_dir($d)) {
            @mkdir($d, 0775, true);
        }

        return $d;
    }

    private function dump(string $db): bool
    {
        if (! function_exists('exec')) {
            return false;
        }
        $bin = null;
        foreach (['C:\\xampp\\mysql\\bin\\mysqldump.exe', '/usr/bin/mysqldump', '/usr/local/bin/mysqldump', 'mysqldump'] as $c) {
            if ($c === 'mysqldump' || is_file($c)) {
                $bin = $c;
                break;
            }
        }
        if ($bin === null) {
            return false;
        }
        $cfg  = (array) (new Database())->default;
        $file = $this->dir() . $db . '-' . date('Ymd-His') . '.sql';
        $cmd  = sprintf(
            '%s --host=%s --port=%s --user=%s %s --single-transaction --skip-lock-tables %s > %s 2>&1',
            escapeshellarg($bin),
            escapeshellarg((string) ($cfg['hostname'] ?? '127.0.0.1')),
            escapeshellarg((string) ($cfg['port'] ?? 3306)),
            escapeshellarg((string) ($cfg['username'] ?? 'root')),
            ($cfg['password'] ?? '') !== '' ? '--password=' . escapeshellarg((string) $cfg['password']) : '',
            escapeshellarg($db),
            escapeshellarg($file),
        );
        @exec($cmd, $o, $code);
        if ($code !== 0 || ! is_file($file) || filesize($file) === 0) {
            @unlink($file);

            return false;
        }

        return true;
    }

    private function prune(int $keepDays): void
    {
        $cut = time() - ($keepDays * 86400);
        foreach (glob($this->dir() . '*.sql') ?: [] as $f) {
            if ((int) filemtime($f) < $cut) {
                @unlink($f);
            }
        }
    }
}
