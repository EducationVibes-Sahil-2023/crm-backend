<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database as DatabaseConfig;
use mysqli;

/**
 * One-command deploy bootstrap.
 *
 *   php spark setup
 *
 * 1. Connects to MySQL (server only) using the credentials from .env.
 * 2. CREATE DATABASE IF NOT EXISTS <database.default.database>.
 * 3. Runs all migrations (builds the full table structure).
 * 4. Seeds the initial admin login (pass --no-seed to skip).
 *
 * Safe to re-run: the database/tables are only created when missing and the
 * seeder upserts, so this is the idempotent way to stand the schema up on a
 * fresh server or in CI/CD.
 */
class Setup extends BaseCommand
{
    protected $group       = 'Database';
    protected $name        = 'setup';
    protected $description  = 'Create the configured database (if missing), run migrations, and seed the admin user.';
    protected $usage       = 'setup [--no-seed]';
    protected $options     = ['--no-seed' => 'Skip seeding the initial admin user.'];

    public function run(array $params)
    {
        $config = new DatabaseConfig();
        $db     = $config->default;

        $host    = $db['hostname'] ?? 'localhost';
        $user    = $db['username'] ?? 'root';
        $pass    = $db['password'] ?? '';
        $port    = (int) ($db['port'] ?? 3306);
        $name    = $db['database'] ?? '';
        $charset = $db['charset'] ?? 'utf8mb4';
        $collat  = $db['DBCollat'] ?? 'utf8mb4_general_ci';

        if ($name === '') {
            CLI::error('No database name configured in .env (database.default.database).');
            return EXIT_ERROR;
        }

        CLI::write("Ensuring database `{$name}` exists on {$host}:{$port} ...", 'yellow');

        // Connect to the server WITHOUT selecting a database, so we can create it.
        try {
            $mysqli = @new mysqli($host, $user, $pass, '', $port);
        } catch (\Throwable $e) {
            CLI::error('Could not connect to MySQL: ' . $e->getMessage());
            return EXIT_ERROR;
        }

        if ($mysqli->connect_errno) {
            CLI::error('Could not connect to MySQL: ' . $mysqli->connect_error);
            return EXIT_ERROR;
        }

        $safeName = str_replace('`', '', $name);
        $sql      = "CREATE DATABASE IF NOT EXISTS `{$safeName}` CHARACTER SET {$charset} COLLATE {$collat}";

        if (! $mysqli->query($sql)) {
            CLI::error('Failed to create database: ' . $mysqli->error);
            $mysqli->close();
            return EXIT_ERROR;
        }
        $mysqli->close();
        CLI::write("Database `{$name}` is ready.", 'green');

        // Build the table structure.
        CLI::write('Running migrations ...', 'yellow');
        $this->call('migrate', ['all' => null]);

        if (! in_array('--no-seed', $params, true) && ! array_key_exists('no-seed', $params)) {
            CLI::write('Seeding admin user ...', 'yellow');
            $this->call('db:seed', ['UserSeeder']);
        }

        CLI::write('Setup complete.', 'green');
        return EXIT_SUCCESS;
    }
}
