<?php

namespace App\Libraries;

use Config\Database;

/**
 * Tiny JSON key/value config store backed by the `settings` table.
 * Used to keep the Gmail OAuth app and SMTP relay credentials in the DB.
 */
class Settings
{
    /** @return array<string,mixed>|null */
    public static function get(string $key): ?array
    {
        try {
            $row = Database::connect()->table('settings')->where('setting_key', $key)->get()->getRowArray();
        } catch (\Throwable $e) {
            return null; // table missing / DB down — caller falls back to defaults
        }
        if (! $row) {
            return null;
        }
        $v = json_decode((string) ($row['setting_value'] ?? ''), true);
        return is_array($v) ? $v : null;
    }

    public static function set(string $key, array $value): void
    {
        $db   = Database::connect();
        $data = [
            'setting_value' => json_encode($value),
            'updated_at'    => date('Y-m-d H:i:s'),
        ];
        if ($db->table('settings')->where('setting_key', $key)->countAllResults() > 0) {
            $db->table('settings')->where('setting_key', $key)->update($data);
        } else {
            $db->table('settings')->insert(['setting_key' => $key] + $data);
        }
    }
}
