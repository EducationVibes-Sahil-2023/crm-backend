<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Web Push subscriptions — one row per browser/device that has granted
 * notification permission and subscribed via the PushManager. The endpoint is
 * the push service URL (FCM/Mozilla/WNS); p256dh + auth are the client keys
 * used to encrypt the payload (RFC 8291). Stored per-tenant (the JWT points the
 * default DB at the tenant database before any query runs).
 */
class CreatePushSubscriptions extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'endpoint'   => ['type' => 'VARCHAR', 'constraint' => 512],
            'p256dh'     => ['type' => 'VARCHAR', 'constraint' => 255],
            'auth'       => ['type' => 'VARCHAR', 'constraint' => 255],
            'user_agent' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        // endpoint is the natural unique key (re-subscribing the same browser upserts).
        $this->forge->addUniqueKey('endpoint');
        $this->forge->addKey('user_id');
        $this->forge->createTable('push_subscriptions');
    }

    public function down()
    {
        $this->forge->dropTable('push_subscriptions', true);
    }
}
