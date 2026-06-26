<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMediaTables extends Migration
{
    public function up()
    {
        // Folders (self-referencing tree via parent_id)
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 255],
            'parent_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('parent_id');
        $this->forge->createTable('media_folders');

        // Files (stored on disk under public/uploads/media, metadata here)
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 255],
            'folder_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'mime'       => ['type' => 'VARCHAR', 'constraint' => 191, 'default' => 'application/octet-stream'],
            'size'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'path'       => ['type' => 'VARCHAR', 'constraint' => 255],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('folder_id');
        $this->forge->createTable('media_files');
    }

    public function down()
    {
        $this->forge->dropTable('media_files');
        $this->forge->dropTable('media_folders');
    }
}
