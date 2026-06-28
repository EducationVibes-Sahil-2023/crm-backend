<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Direct (1:1) chat messages between two login accounts in the same workspace.
 * Stored per-tenant — the JWT points the default DB at the caller's database, so
 * a client and their users only ever see each other's messages. The platform
 * super-admin is not a row in `users`, so it never participates.
 */
class CreateChatMessages extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'sender_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'recipient_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'body'         => ['type' => 'TEXT'],
            'read_at'      => ['type' => 'DATETIME', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        // Fast lookups for a conversation between two people, and unread counts.
        $this->forge->addKey(['sender_id', 'recipient_id']);
        $this->forge->addKey(['recipient_id', 'read_at']);
        $this->forge->createTable('chat_messages');
    }

    public function down()
    {
        $this->forge->dropTable('chat_messages', true);
    }
}
