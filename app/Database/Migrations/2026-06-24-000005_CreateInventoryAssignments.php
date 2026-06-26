<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateInventoryAssignments extends Migration
{
    public function up()
    {
        // Units of an inventory item issued to a user (tracks returns).
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'item_id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'assignee_name'  => ['type' => 'VARCHAR', 'constraint' => 120],
            'assignee_email' => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'qty'            => ['type' => 'INT', 'constraint' => 11, 'default' => 1],
            'qty_returned'   => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'status'         => ['type' => 'VARCHAR', 'constraint' => 12, 'default' => 'issued'], // issued|partial|returned
            'note'           => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'asset_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'issued_by'      => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'issued_at'      => ['type' => 'DATETIME', 'null' => true],
            'returned_at'    => ['type' => 'DATETIME', 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('item_id');
        $this->forge->addKey('asset_id');
        $this->forge->createTable('inventory_assignments');
    }

    public function down()
    {
        $this->forge->dropTable('inventory_assignments');
    }
}
