<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * The original `vendors` table only held a few fields; the CRM vendor form
 * captures more. Add the missing columns so vendors persist as full rows.
 * Guarded per-column so it's safe to re-run on already-migrated databases.
 */
class ExtendVendorsTable extends Migration
{
    private array $columns = [
        'contact_person' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
        'website'        => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
        'city'           => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
        'state'          => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
        'zip'            => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
        'country'        => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
        'payment_terms'  => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
        'status'         => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'default' => 'Active'],
        'notes'          => ['type' => 'TEXT', 'null' => true],
    ];

    public function up()
    {
        $db = Database::connect();
        if (! $db->tableExists('vendors')) {
            return; // base table not created yet — nothing to extend
        }
        foreach ($this->columns as $name => $def) {
            if (! $db->fieldExists($name, 'vendors')) {
                $this->forge->addColumn('vendors', [$name => $def]);
            }
        }
    }

    public function down()
    {
        $db = Database::connect();
        foreach (array_keys($this->columns) as $name) {
            if ($db->fieldExists($name, 'vendors')) {
                $this->forge->dropColumn('vendors', $name);
            }
        }
    }
}
