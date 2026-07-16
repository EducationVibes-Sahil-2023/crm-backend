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
            // A before-filter short-circuit skips the CORS *after* filter, so we
            // must add the CORS header here — otherwise the browser blocks the 401
            // and the fetch fails with a generic "Failed to fetch" (looks like the
            // backend is down) instead of surfacing a clean "please sign in".
            return $this->withCors($request, Services::response()
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON([
                    'status'   => 401,
                    'error'    => 401,
                    'messages' => ['error' => 'Authentication required. Please sign in again.'],
                ]));
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
        // NOTE: the slug part may contain underscores (e.g. tenant_acme_corp when a
        // client is provisioned with an explicit multi-word db name). The pattern
        // MUST allow "_" — matching the validation used everywhere else — or the DB
        // switch silently no-ops and the client's data lands in the MAIN database.
        if ($tenant !== '' && preg_match('/^tenant_[a-z0-9_]+$/', $tenant)) {
            config('Database')->default['database'] = $tenant;
            $request->jwtTenant = $tenant;
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // no-op
    }

    /**
     * Echo the request Origin on the response when it matches the CORS config,
     * mirroring Config\Cors so a short-circuited 401 is still readable by the
     * browser (the framework CORS *after* filter never runs on a short-circuit).
     */
    private function withCors(RequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin === '') {
            return $response;
        }

        $cfg     = config('Cors')->default ?? [];
        $allowed = in_array($origin, $cfg['allowedOrigins'] ?? [], true);
        if (! $allowed) {
            foreach (($cfg['allowedOriginsPatterns'] ?? []) as $pattern) {
                if (preg_match('#\A' . $pattern . '\z#', $origin) === 1) {
                    $allowed = true;
                    break;
                }
            }
        }
        if (! $allowed) {
            return $response;
        }

        $response->setHeader('Access-Control-Allow-Origin', $origin);
        $response->appendHeader('Vary', 'Origin');
        if (! empty($cfg['supportsCredentials'])) {
            $response->setHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
