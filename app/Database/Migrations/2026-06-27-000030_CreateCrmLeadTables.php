<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * CRM — Leads domain. The lead is the core entity; notes, reminders, activities,
 * calls, transfers and visitor requests hang off it by lead_id.
 */
class CreateCrmLeadTables extends Migration
{
    public function up()
    {
        // Main lead record.
        $this->forge->addField([
            'id'               => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'             => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'company'          => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'phone'            => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'alt_phone'        => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'email'            => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'city'             => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'state'            => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'status'           => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'source'           => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'type'             => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'channel'          => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'reference_name'   => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'assigned_to'      => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'created_by'       => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'follow_up_date'   => ['type' => 'DATETIME', 'null' => true],
            'connected_date'   => ['type' => 'DATETIME', 'null' => true],
            'assignation_date' => ['type' => 'DATETIME', 'null' => true],
            'response_time'    => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'total_call_count' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'call_count'       => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'duration'         => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'form_id'          => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'custom'           => ['type' => 'LONGTEXT', 'null' => true], // JSON for custom fields
            'deleted'          => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('status');
        $this->forge->addKey('assigned_to');
        $this->forge->addKey('deleted');
        $this->forge->createTable('leads');

        // Notes.
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'lead_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'text'       => ['type' => 'TEXT', 'null' => true],
            'created_by' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('lead_id');
        $this->forge->createTable('lead_notes');

        // Reminders / follow-ups.
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'lead_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'title'      => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'due'        => ['type' => 'DATETIME', 'null' => true],
            'done'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_by' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('lead_id');
        $this->forge->createTable('lead_reminders');

        // Activity timeline.
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'lead_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'kind'       => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'text'       => ['type' => 'TEXT', 'null' => true],
            'created_by' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('lead_id');
        $this->forge->createTable('lead_activities');

        // Call logs.
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'lead_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'direction'    => ['type' => 'VARCHAR', 'constraint' => 12, 'null' => true],
            'duration_sec' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'outcome'      => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'notes'        => ['type' => 'TEXT', 'null' => true],
            'created_by'   => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('lead_id');
        $this->forge->createTable('lead_calls');

        // Transfers between owners.
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'lead_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'lead_name'  => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'from_owner' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'to_owner'   => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'reason'     => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('lead_id');
        $this->forge->createTable('lead_transfers');

        // Visitor requests.
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'lead_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'phone'      => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'purpose'    => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'status'     => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'scheduled'  => ['type' => 'DATETIME', 'null' => true],
            'assigned_to'=> ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('visitor_requests');

        // Custom field definitions.
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'field_key'  => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'label'      => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'type'       => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'options'    => ['type' => 'TEXT', 'null' => true], // JSON list for selects
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('lead_custom_fields');
    }

    public function down()
    {
        foreach (['lead_custom_fields', 'visitor_requests', 'lead_transfers', 'lead_calls', 'lead_activities', 'lead_reminders', 'lead_notes', 'leads'] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}
