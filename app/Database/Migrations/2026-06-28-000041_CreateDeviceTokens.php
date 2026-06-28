<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Native mobile push tokens (FCM/APNs via Capacitor). Distinct from
 * `push_subscriptions` (browser Web Push): a native registration is a single
 * opaque token string, delivered through Firebase Cloud Messaging rather than
 * an encrypted Web Push endpoint.
 */
class CreateDeviceTokens extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'token'      => ['type' => 'VARCHAR', 'constraint' => 512],
            'platform'   => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true], // ios | android
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('token');
        $this->forge->addKey('user_id');
        $this->forge->createTable('device_tokens');
    }

    public function down()
    {
        $this->forge->dropTable('device_tokens', true);
    }
}
