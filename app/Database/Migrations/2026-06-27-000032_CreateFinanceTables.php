<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Finance — invoices/quotations with line items, payments, expenses, vendors. */
class CreateFinanceTables extends Migration
{
    public function up()
    {
        // Vendors.
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'email'      => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'phone'      => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'gstin'      => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'address'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'category'   => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('vendors');

        // Invoices.
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'number'       => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'customer'     => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'customer_email' => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'lead_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'issue_date'   => ['type' => 'DATE', 'null' => true],
            'due_date'     => ['type' => 'DATE', 'null' => true],
            'subtotal'     => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'tax'          => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'total'        => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'paid'         => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'status'       => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true], // Draft/Sent/Paid/Overdue
            'notes'        => ['type' => 'TEXT', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('status');
        $this->forge->createTable('invoices');

        $this->lineItems('invoice_items', 'invoice_id');

        // Quotations.
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'number'     => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'customer'   => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'lead_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'issue_date' => ['type' => 'DATE', 'null' => true],
            'valid_until'=> ['type' => 'DATE', 'null' => true],
            'subtotal'   => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'tax'        => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'total'      => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'status'     => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'notes'      => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('quotations');

        $this->lineItems('quotation_items', 'quotation_id');

        // Payments.
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'invoice_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'customer'   => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'amount'     => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'method'     => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'reference'  => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'paid_at'    => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('invoice_id');
        $this->forge->createTable('payments');

        // Expenses.
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'title'      => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'category'   => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'vendor_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'amount'     => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'spent_on'   => ['type' => 'DATE', 'null' => true],
            'status'     => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'receipt_url'=> ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'notes'      => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('expenses');
    }

    /** Shared line-item table shape for invoices & quotations. */
    private function lineItems(string $table, string $fk): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            $fk           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'qty'         => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 1],
            'rate'        => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'amount'      => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey($fk);
        $this->forge->createTable($table);
    }

    public function down()
    {
        foreach (['expenses', 'payments', 'quotation_items', 'quotations', 'invoice_items', 'invoices', 'vendors'] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}
