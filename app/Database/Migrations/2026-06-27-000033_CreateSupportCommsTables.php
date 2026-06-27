<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Support tickets (+ comments) and communication/content tables. */
class CreateSupportCommsTables extends Migration
{
    public function up()
    {
        // Support tickets.
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'number'      => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'subject'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'requester'   => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'email'       => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'category'    => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'priority'    => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'status'      => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true], // Open/Pending/Resolved/Closed
            'assigned_to' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'description' => ['type' => 'TEXT', 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('status');
        $this->forge->createTable('support_tickets');

        // Ticket comments / replies.
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'ticket_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'author'     => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'body'       => ['type' => 'TEXT', 'null' => true],
            'internal'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('ticket_id');
        $this->forge->createTable('ticket_comments');

        // Announcements.
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'title'      => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'body'       => ['type' => 'TEXT', 'null' => true],
            'audience'   => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'pinned'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'author'     => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('announcements');

        // Activity log (audit trail across the app).
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'category'   => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'action'     => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'actor'      => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'meta'       => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('category');
        $this->forge->createTable('activity_log');
    }

    public function down()
    {
        foreach (['activity_log', 'announcements', 'ticket_comments', 'support_tickets'] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}
