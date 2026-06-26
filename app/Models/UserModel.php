<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table            = 'users';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';

    protected $allowedFields = [
        'name', 'username', 'email', 'password', 'api_token',
        'role', 'active', 'phone', 'department', 'designation', 'avatar',
        'twofa_enabled', 'twofa_secret',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Find a user by email or username (the login identifier).
     */
    public function findByIdentifier(string $identifier): ?array
    {
        return $this->groupStart()
            ->where('email', $identifier)
            ->orWhere('username', $identifier)
            ->groupEnd()
            ->first();
    }

    public function findByToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        return $this->where('api_token', $token)->first();
    }

    /** Public-safe representation (never leaks password / 2FA secret). */
    public function publicUser(array $user): array
    {
        return [
            'id'            => (int) $user['id'],
            'name'          => $user['name'],
            'username'      => $user['username'],
            'email'         => $user['email'],
            'role'          => $user['role'] ?? 'Member',
            'active'        => (int) ($user['active'] ?? 1) === 1,
            'phone'         => $user['phone'] ?? null,
            'department'    => $user['department'] ?? null,
            'designation'   => $user['designation'] ?? null,
            'avatar'        => $user['avatar'] ?? null,
            'twofa_enabled' => (int) ($user['twofa_enabled'] ?? 0) === 1,
            'created_at'    => $user['created_at'] ?? null,
        ];
    }
}
