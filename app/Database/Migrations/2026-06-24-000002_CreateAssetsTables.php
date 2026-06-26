<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAssetsTables extends Migration
{
    public function up()
    {
        // Assets — the tracked record + costing + verification workflow state.
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tag'             => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'name'            => ['type' => 'VARCHAR', 'constraint' => 255],
            'category'        => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'description'     => ['type' => 'TEXT', 'null' => true],
            'serial_number'   => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'manufacturer'    => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'model'           => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'location'        => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'condition'       => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'vendor'          => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'owner_name'      => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'owner_email'     => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'purchase_date'   => ['type' => 'DATE', 'null' => true],
            'purchase_cost'   => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'repair_cost'     => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'warranty_years'  => ['type' => 'DECIMAL', 'constraint' => '4,1', 'default' => 0],
            'warranty_expiry' => ['type' => 'DATE', 'null' => true],
            'status'          => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pending'],
            'reject_reason'   => ['type' => 'TEXT', 'null' => true],
            'verified_by'     => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'verified_at'     => ['type' => 'DATETIME', 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('status');
        $this->forge->createTable('assets');

        // Activity timeline — updates, transitions and comments for each asset.
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'asset_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'type'       => ['type' => 'VARCHAR', 'constraint' => 30],
            'actor'      => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'role'       => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'message'    => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('asset_id');
        $this->forge->createTable('asset_events');
    }

    public function down()
    {
        $this->forge->dropTable('asset_events');
        $this->forge->dropTable('assets');
    }
}
