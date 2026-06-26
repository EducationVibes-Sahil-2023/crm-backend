<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Generic per-workspace JSON document store. Replaces the front-end's
 * localStorage: every module persists its blob under a `store_key` here, so all
 * app data lives in the database and is shared across devices/browsers.
 *
 * Lives in whichever DB the request is routed to (default, or a tenant DB for an
 * impersonated client session), so data is naturally workspace-scoped.
 */
class CreateAppStoreTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'store_key'  => ['type' => 'VARCHAR', 'constraint' => 120],
            'data'       => ['type' => 'LONGTEXT', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('store_key');
        $this->forge->createTable('app_store');
    }

    public function down()
    {
        $this->forge->dropTable('app_store');
    }
}
