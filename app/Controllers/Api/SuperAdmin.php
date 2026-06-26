<?php

namespace App\Controllers\Api;

use App\Libraries\Jwt;
use CodeIgniter\RESTful\ResourceController;

/**
 * Super-admin (platform owner) bridge to JWT-protected APIs.
 *
 * The super-admin console authenticates in the browser (localStorage session),
 * which carries no JWT. This endpoint exchanges the super-admin credentials for
 * a signed JWT whose subject is the fixed id "super-admin", letting the console
 * reuse the same protected APIs (e.g. Gmail) with isolated per-user storage.
 */
class SuperAdmin extends ResourceController
{
    protected $format = 'json';

    public const SUBJECT = 'super-admin';

    /** POST /api/super-admin/token  { email, password } -> { token } */
    public function token()
    {
        $in       = $this->request->getJSON(true) ?: [];
        $email    = strtolower(trim((string) ($in['email'] ?? '')));
        $password = (string) ($in['password'] ?? '');

        $expectedEmail = strtolower((string) (env('superadmin.email') ?: 'superadmin@crm-cloud.app'));
        $expectedPass  = (string) (env('superadmin.password') ?: 'super123');

        if (! hash_equals($expectedEmail, $email) || ! hash_equals($expectedPass, $password)) {
            return $this->failUnauthorized('Invalid super admin credentials.');
        }

        $token = Jwt::encode(['sub' => self::SUBJECT, 'role' => 'super-admin']);

        return $this->respond(['token' => $token]);
    }
}
