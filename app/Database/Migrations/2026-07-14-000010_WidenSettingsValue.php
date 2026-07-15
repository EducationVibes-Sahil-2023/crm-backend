<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Widen settings.setting_value from TEXT to LONGTEXT.
 *
 * The platform config JSON (Super Admin → Settings) embeds base64 logo/favicon
 * data URLs — up to ~680 KB each — plus the default appearance/menu blobs. That
 * overflows TEXT's ~64 KB limit: MySQL silently truncates the value on save, so
 * the stored JSON is cut off mid-string and `json_decode` returns null. The API
 * then serves an empty config and the whole thing (logo + everything else)
 * reverts to defaults — i.e. "it won't save". LONGTEXT removes the ceiling.
 */
class WidenSettingsValue extends Migration
{
    public function up()
    {
        $this->forge->modifyColumn('settings', [
            'setting_value' => ['type' => 'LONGTEXT', 'null' => true],
        ]);
    }

    public function down()
    {
        $this->forge->modifyColumn('settings', [
            'setting_value' => ['type' => 'TEXT', 'null' => true],
        ]);
    }
}
