<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Registry (in the main DB) of provisioned client workspaces — maps a company
 * to its isolated `tenant_<slug>` database, admin login, and subscription plan.
 * Used by the super-admin console to list clients and drive plan-based modules.
 */
class CreateTenantsRegistry extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'company'     => ['type' => 'VARCHAR', 'constraint' => 191],
            'slug'        => ['type' => 'VARCHAR', 'constraint' => 64],
            'db_name'     => ['type' => 'VARCHAR', 'constraint' => 80],
            'admin_email' => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'plan'        => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'starter'],
            'active'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('db_name');
        $this->forge->createTable('tenants');
    }

    public function down()
    {
        $this->forge->dropTable('tenants');
    }
}
