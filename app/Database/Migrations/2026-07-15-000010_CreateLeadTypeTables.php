<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Lead setup lists that were missing a table: lead types + sub-statuses. */
class CreateLeadTypeTables extends Migration
{
    public function up()
    {
        foreach (['lead_types', 'lead_sub_statuses'] as $table) {
            $this->forge->addField([
                'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'name'       => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
                'color'      => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
                'sort_order' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
                'active'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->createTable($table, true);
        }
    }

    public function down()
    {
        $this->forge->dropTable('lead_sub_statuses', true);
        $this->forge->dropTable('lead_types', true);
    }
}
