<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** HRMS — employees and the time/leave records that reference them. */
class CreateHrTables extends Migration
{
    public function up()
    {
        // Employees.
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'employee_code'=> ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'name'         => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'email'        => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'phone'        => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'department'   => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'designation'  => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'location'     => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'shift'        => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'manager'      => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'date_joined'  => ['type' => 'DATE', 'null' => true],
            'salary'       => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'status'       => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'avatar'       => ['type' => 'TEXT', 'null' => true],
            'meta'         => ['type' => 'LONGTEXT', 'null' => true], // JSON for extra HR fields
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('department');
        $this->forge->addKey('status');
        $this->forge->createTable('employees');

        // Attendance (one row per employee per day).
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'employee_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'date'        => ['type' => 'DATE', 'null' => true],
            'check_in'    => ['type' => 'DATETIME', 'null' => true],
            'check_out'   => ['type' => 'DATETIME', 'null' => true],
            'status'      => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true], // Present/Absent/Late/Leave
            'worked_mins' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'lat'         => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'lng'         => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'selfie_url'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('employee_id');
        $this->forge->addKey('date');
        $this->forge->createTable('attendance');

        // Leave requests.
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'employee_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'type'        => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'from_date'   => ['type' => 'DATE', 'null' => true],
            'to_date'     => ['type' => 'DATE', 'null' => true],
            'days'        => ['type' => 'DECIMAL', 'constraint' => '4,1', 'default' => 0],
            'reason'      => ['type' => 'TEXT', 'null' => true],
            'status'      => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true], // Pending/Approved/Rejected
            'approver'    => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('employee_id');
        $this->forge->createTable('leaves');

        // Holidays calendar.
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'date'       => ['type' => 'DATE', 'null' => true],
            'type'       => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'region'     => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('holidays');

        // Shifts.
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'start_time' => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'end_time'   => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'grace_mins' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('shifts');
    }

    public function down()
    {
        foreach (['shifts', 'holidays', 'leaves', 'attendance', 'employees'] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}
