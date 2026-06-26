<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        $admin = [
            'name'     => 'Administrator',
            'username' => 'admin',
            'email'    => 'admin@nexus.com',
            'password' => password_hash('admin123', PASSWORD_DEFAULT),
        ];

        // Upsert: update the password if the admin already exists, else insert.
        $existing = $this->db->table('users')->where('email', $admin['email'])->get()->getRowArray();

        if ($existing) {
            $this->db->table('users')->where('id', $existing['id'])->update([
                'name'       => $admin['name'],
                'username'   => $admin['username'],
                'password'   => $admin['password'],
                'updated_at' => $now,
            ]);
        } else {
            $this->db->table('users')->insert($admin + [
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
