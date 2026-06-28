<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Device call log rows for the Call Tracker. One row per call the companion app
 * uploads (or that a user logs manually). Stored per-tenant and scoped to the
 * user who owns the device. Lead-matching happens in the app against CRM leads.
 */
class CreateCallsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'user_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'number'       => ['type' => 'VARCHAR', 'constraint' => 32],
            'raw_name'     => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'direction'    => ['type' => 'VARCHAR', 'constraint' => 16],
            'duration_sec' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'device'       => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'called_at'    => ['type' => 'DATETIME', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['user_id', 'called_at']);
        $this->forge->createTable('calls');
    }

    public function down()
    {
        $this->forge->dropTable('calls', true);
    }
}
