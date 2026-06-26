<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Extend the tenant registry so the super-admin console can be fully
 * database-backed (no browser storage): admin name, region, a 3-state status
 * (Active / Trial / Suspended) and storage allocation.
 */
class AddTenantProfileColumns extends Migration
{
    public function up()
    {
        $fields = [];
        if (! $this->db->fieldExists('admin_name', 'tenants')) {
            $fields['admin_name'] = ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'after' => 'admin_email'];
        }
        if (! $this->db->fieldExists('region', 'tenants')) {
            $fields['region'] = ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true, 'after' => 'plan'];
        }
        if (! $this->db->fieldExists('status', 'tenants')) {
            $fields['status'] = ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'Active', 'after' => 'active'];
        }
        if (! $this->db->fieldExists('storage_gb', 'tenants')) {
            $fields['storage_gb'] = ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0, 'after' => 'status'];
        }
        if ($fields !== []) {
            $this->forge->addColumn('tenants', $fields);
        }
    }

    public function down()
    {
        foreach (['admin_name', 'region', 'status', 'storage_gb'] as $col) {
            if ($this->db->fieldExists($col, 'tenants')) {
                $this->forge->dropColumn('tenants', $col);
            }
        }
    }
}
