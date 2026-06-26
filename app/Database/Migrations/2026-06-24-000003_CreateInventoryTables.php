<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateInventoryTables extends Migration
{
    public function up()
    {
        // Stock items
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'sku'           => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'name'          => ['type' => 'VARCHAR', 'constraint' => 255],
            'category'      => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'description'   => ['type' => 'TEXT', 'null' => true],
            'unit'          => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'pcs'],
            'quantity'      => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'reorder_level' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'unit_price'    => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'location'      => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'supplier'      => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('inventory_items');

        // Stock movements (in / out / adjust) for traceability
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'item_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'type'          => ['type' => 'VARCHAR', 'constraint' => 10],
            'qty'           => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'balance_after' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'reason'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'actor'         => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('item_id');
        $this->forge->createTable('inventory_movements');
    }

    public function down()
    {
        $this->forge->dropTable('inventory_movements');
        $this->forge->dropTable('inventory_items');
    }
}
