<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The "Users" directory (team profiles) shown on the Users page. Per-tenant:
 * this table is copied (blank) into every client's database, so each client
 * sees only their own team instead of shared demo data.
 */
class CreateDirectoryTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'                => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'              => ['type' => 'VARCHAR', 'constraint' => 150],
            'email'             => ['type' => 'VARCHAR', 'constraint' => 191],
            'phone'             => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'designation'       => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'department'        => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'role'              => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'status'            => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'Active'],
            'bio'               => ['type' => 'TEXT', 'null' => true],
            'profile'           => ['type' => 'TEXT', 'null' => true],
            'password'          => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'linkedin'          => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'twitter'           => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'github'            => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'joining_date'      => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'employee_id'       => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'company_code'      => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'city'              => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'state'             => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'address'           => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'zip'               => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'extra_permissions' => ['type' => 'TEXT', 'null' => true],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('directory');
    }

    public function down()
    {
        $this->forge->dropTable('directory');
    }
}
