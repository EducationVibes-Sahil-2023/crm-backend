<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Turns the bare login table into manageable accounts:
 *  - role / active drive the admin Users panel + real-time forced logout
 *  - twofa_* drive authenticator-app (TOTP) two-step verification
 *  - profile fields mirror what the Users page edits
 */
class AddUserAccountFields extends Migration
{
    public function up()
    {
        $fields = [
            'role'          => ['type' => 'VARCHAR', 'constraint' => 60, 'default' => 'Member', 'after' => 'email'],
            'active'        => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1, 'after' => 'role'],
            'phone'         => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'after' => 'active'],
            'department'    => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'after' => 'phone'],
            'designation'   => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'after' => 'department'],
            'avatar'        => ['type' => 'TEXT', 'null' => true, 'after' => 'designation'],
            'twofa_enabled' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'avatar'],
            'twofa_secret'  => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'after' => 'twofa_enabled'],
        ];
        $this->forge->addColumn('users', $fields);

        // Make the seeded administrator a full admin account.
        $this->db->table('users')->where('email', 'admin@nexus.com')->update(['role' => 'Administrator']);
    }

    public function down()
    {
        $this->forge->dropColumn('users', [
            'role', 'active', 'phone', 'department', 'designation', 'avatar', 'twofa_enabled', 'twofa_secret',
        ]);
    }
}
