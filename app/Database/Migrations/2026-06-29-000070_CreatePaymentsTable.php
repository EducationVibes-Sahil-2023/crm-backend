<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Subscription payment records — one row per Razorpay checkout attempt. Stored
 * per-tenant (the JWT routes the DB), so each workspace keeps its own billing
 * history. A row is created when an order is generated (status "created") and
 * flipped to "paid" once the payment signature is verified.
 */
class CreatePaymentsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'                  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'user_id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'plan_id'             => ['type' => 'VARCHAR', 'constraint' => 64],
            'plan_name'           => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'billing_cycle'       => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'monthly'],
            'amount'              => ['type' => 'INT', 'constraint' => 11, 'default' => 0], // smallest currency unit (paise/cents)
            'currency'            => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'INR'],
            'status'              => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'created'], // created | paid | failed
            'razorpay_order_id'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'razorpay_payment_id' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'razorpay_signature'  => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'payer_email'         => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'payer_name'          => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'paid_at'             => ['type' => 'DATETIME', 'null' => true],
            'created_at'          => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('razorpay_order_id');
        $this->forge->addKey(['user_id', 'created_at']);
        $this->forge->createTable('subscription_payments');
    }

    public function down()
    {
        $this->forge->dropTable('subscription_payments', true);
    }
}
