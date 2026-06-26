<?php

namespace App\Filters;

use App\Libraries\Jwt;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

class JwtAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // Never block CORS preflight requests.
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return;
        }

        $header = $request->getHeaderLine('Authorization');
        $token  = preg_match('/Bearer\s+(.+)/i', $header, $m) ? trim($m[1]) : '';

        $claims = $token !== '' ? Jwt::decode($token) : null;

        if ($claims === null) {
            return Services::response()
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON([
                    'status'   => 401,
                    'error'    => 401,
                    'messages' => ['error' => 'Authentication required. Please sign in again.'],
                ]);
        }

        // Make the authenticated user id available to controllers if needed.
        $request->jwtUserId = $claims['sub'] ?? null;

        // Multi-tenant routing: a tenant-scoped token (minted at login or by
        // super-admin impersonation) carries its database name. Point the default
        // DB connection at it BEFORE any model connects, so every query for this
        // request runs against that client's isolated database.
        // DB access is lazy (no connection exists this early in the request), so
        // mutating the default group's database here means the first model
        // connect() lands on the tenant database.
        $tenant = (string) ($claims['tenant'] ?? '');
        if ($tenant !== '' && preg_match('/^tenant_[a-z0-9]+$/', $tenant)) {
            config('Database')->default['database'] = $tenant;
            $request->jwtTenant = $tenant;
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // no-op
    }
}
