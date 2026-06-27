<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Lookup / config tables driving dropdowns across the app. */
class CreateConfigTables extends Migration
{
    public function up()
    {
        $this->simpleList('lead_statuses', ['color' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true]]);
        $this->simpleList('lead_sources');
        $this->simpleList('departments');
        $this->simpleList('designations');
        $this->simpleList('locations', [
            'city'  => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'state' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
        ]);
        $this->simpleList('ticket_categories');
        $this->simpleList('ticket_priorities', ['weight' => ['type' => 'INT', 'constraint' => 11, 'default' => 0]]);
    }

    /** A name + sort-order list, plus any extra columns. */
    private function simpleList(string $table, array $extra = []): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            ...$extra,
            'sort_order' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'active'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable($table);
    }

    public function down()
    {
        foreach (['ticket_priorities', 'ticket_categories', 'locations', 'designations', 'departments', 'lead_sources', 'lead_statuses'] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}
