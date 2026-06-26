<?php

namespace App\Models;

use CodeIgniter\Model;

class AssetEventModel extends Model
{
    protected $table            = 'asset_events';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = ['asset_id', 'type', 'actor', 'role', 'message'];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = '';

    /** Append a timeline entry for an asset. */
    public function log(int $assetId, string $type, ?string $actor, ?string $role, ?string $message = null): void
    {
        $this->insert([
            'asset_id' => $assetId,
            'type'     => $type,
            'actor'    => $actor,
            'role'     => $role,
            'message'  => $message,
        ]);
    }

    /** @return list<array> events for an asset, newest first */
    public function forAsset(int $assetId): array
    {
        return $this->where('asset_id', $assetId)->orderBy('id', 'DESC')->findAll();
    }
}
