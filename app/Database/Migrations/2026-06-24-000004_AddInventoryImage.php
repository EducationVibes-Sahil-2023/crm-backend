<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddInventoryImage extends Migration
{
    public function up()
    {
        $this->forge->addColumn('inventory_items', [
            'image_url' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'supplier'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('inventory_items', 'image_url');
    }
}
