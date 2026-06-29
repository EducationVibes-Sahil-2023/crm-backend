<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Track when a client last signed in THEMSELVES (a real tenant login), so the
 * super-admin console can show each client's last activity. This is distinct
 * from super-admin impersonation, which does not stamp it.
 */
class AddTenantLastLogin extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('last_login_at', 'tenants')) {
            $this->forge->addColumn('tenants', [
                'last_login_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'storage_gb'],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('last_login_at', 'tenants')) {
            $this->forge->dropColumn('tenants', 'last_login_at');
        }
    }
}
