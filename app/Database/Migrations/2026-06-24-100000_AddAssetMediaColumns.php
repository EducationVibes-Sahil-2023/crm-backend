<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAssetMediaColumns extends Migration
{
    public function up()
    {
        $this->forge->addColumn('assets', [
            'image_url'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'category'],
            'bill_url'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'purchase_cost'],
            'warranty_doc_url' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'warranty_expiry'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('assets', ['image_url', 'bill_url', 'warranty_doc_url']);
    }
}
