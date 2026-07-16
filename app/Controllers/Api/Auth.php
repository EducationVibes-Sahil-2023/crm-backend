<?php

namespace App\Controllers\Api;

use App\Libraries\Jwt;
use App\Libraries\SmtpService;
use App\Libraries\Totp;
use App\Models\UserModel;
use CodeIgniter\RESTful\ResourceController;

class Auth extends ResourceController
{
    protected $format = 'json';

    private const ISSUER = 'Nexus CRM';

    /** POST /api/auth/login  { identifier|email|username, password } */
    public function login()
    {
        $data       = $this->request->getJSON(true) ?? $this->request->getPost();
        $identifier = trim((string) ($data['identifier'] ?? $data['email'] ?? $data['username'] ?? ''));
        $password   = (string) ($data['password'] ?? '');

        if ($identifier === '' || $password === '') {
            return $this->failValidationErrors('Email and password are required.');
        }

        $users  = new UserModel();
        $tenant = null;

        // 1. Try the platform (default) database first. A match only counts if the
        //    password verifies.
        $user   = $users->findByIdentifier($identifier);
        $authed = $user !== null && password_verify($password, (string) $user['password']);

        // 2. If that didn't authenticate, resolve the login against the CLIENT
        //    databases, so a client always signs in against their OWN tenant DB —
        //    even when their email/username collides with a platform account (in
        //    which case the main-DB match above failed the password check). This is
        //    a real client login (not super-admin impersonation), so it stamps the
        //    tenant's last-login for the super-admin console.
        if (! $authed) {
            $found = $this->findTenantUser($identifier);
            if ($found !== null && password_verify($password, (string) $found['user']['password'])) {
                $user   = $found['user'];
                $tenant = $found['db'];
                $authed = true;
            }
        }

        if (! $authed || $user === null) {
            return $this->failUnauthorized('Invalid email or password.');
        }

        if ((int) ($user['active'] ?? 1) !== 1) {
            return $this->failForbidden('Your account has been deactivated. Please contact an administrator.');
        }

        // Two-step verification: hand back a short-lived challenge instead of a
        // session token. The client then posts the authenticator code to /verify.
        // Carry the tenant so /verify can complete the login in the right DB.
        if ((int) ($user['twofa_enabled'] ?? 0) === 1) {
            $claims = ['sub' => (int) $user['id'], 'purpose' => '2fa'];
            if ($tenant !== null) {
                $claims['tenant'] = $tenant;
            }
            $challenge = Jwt::encode($claims, 300);

            return $this->respond([
                'twofa_required' => true,
                'challenge'      => $challenge,
                'name'           => $user['name'],
            ]);
        }

        $this->stampTenantLogin($tenant);

        return $this->respond($this->session($users, $user, $tenant));
    }

    /**
     * POST /api/auth/forgot-password  { email }
     * Self-service recovery. Emails a short-lived reset link to the account.
     * Always responds success (never reveals whether an email is registered),
     * so it can't be used to enumerate accounts.
     */
    public function forgotPassword()
    {
        $data  = $this->request->getJSON(true) ?? $this->request->getPost();
        $email = trim((string) ($data['email'] ?? $data['identifier'] ?? ''));

        $generic = ['ok' => true, 'message' => 'If an account exists for that email, a reset link is on its way.'];

        if ($email === '') {
            return $this->failValidationErrors('An email address is required.');
        }

        $users = new UserModel();
        $user  = $users->findByIdentifier($email);

        // Only mint + send for a real, active account — but the response is the
        // same either way to avoid leaking which emails are registered.
        if ($user !== null && (int) ($user['active'] ?? 1) === 1) {
            // 30-minute single-purpose reset token (stateless, like the 2FA challenge).
            $token   = Jwt::encode(['sub' => (int) $user['id'], 'purpose' => 'pwreset'], 1800);
            $resetUrl = $this->resetUrl($token);

            $name = trim((string) ($user['name'] ?? '')) ?: 'there';
            $body = "Hi {$name},\r\n\r\n"
                . "We received a request to reset your password. Click the link below to choose a new one:\r\n\r\n"
                . "{$resetUrl}\r\n\r\n"
                . "This link expires in 30 minutes. If you didn't request this, you can safely ignore this email — your password won't change.\r\n";

            $sent = (new SmtpService())->send((string) $user['email'], 'Reset your password', $body);

            // In non-production, hand back the link when mail isn't configured so
            // the flow is testable locally. Never leaked in production.
            if (! ($sent['ok'] ?? false) && ENVIRONMENT !== 'production') {
                $generic['devResetUrl'] = $resetUrl;
            }
        }

        return $this->respond($generic);
    }

    /**
     * POST /api/auth/reset-password  { token, password }
     * Completes recovery: validates the emailed token and sets the new password.
     */
    public function resetPassword()
    {
        $data     = $this->request->getJSON(true) ?? $this->request->getPost();
        $token    = (string) ($data['token'] ?? '');
        $password = (string) ($data['password'] ?? '');

        $claims = Jwt::decode($token);
        if ($claims === null || ($claims['purpose'] ?? '') !== 'pwreset') {
            return $this->failUnauthorized('This reset link is invalid or has expired. Please request a new one.');
        }
        if (strlen($password) < 6) {
            return $this->failValidationErrors('Your new password must be at least 6 characters.');
        }

        $users = new UserModel();
        $user  = $users->find((int) ($claims['sub'] ?? 0));
        if ($user === null || (int) ($user['active'] ?? 1) !== 1) {
            return $this->failUnauthorized('Account unavailable. Please request a new reset link.');
        }

        $users->update($user['id'], ['password' => password_hash($password, PASSWORD_DEFAULT)]);

        return $this->respond(['ok' => true, 'message' => 'Your password has been reset. You can now sign in.']);
    }

    /** Build the front-end reset URL, honouring the request Origin where present. */
    private function resetUrl(string $token): string
    {
        $origin = trim((string) $this->request->getHeaderLine('Origin'));
        if ($origin === '') {
            $referer = (string) $this->request->getHeaderLine('Referer');
            if ($referer !== '' && preg_match('#^https?://[^/]+#i', $referer, $m)) {
                $origin = $m[0];
            }
        }
        if ($origin === '') {
            $origin = rtrim((string) (env('app.frontendURL') ?? 'http://localhost:3000'), '/');
        }

        return rtrim($origin, '/') . '/reset-password?token=' . rawurlencode($token);
    }

    /** POST /api/auth/2fa/verify  { challenge, code } — completes a 2FA login. */
    public function verifyTwofa()
    {
        $data      = $this->request->getJSON(true) ?? $this->request->getPost();
        $challenge = (string) ($data['challenge'] ?? '');
        $code      = (string) ($data['code'] ?? '');

        $claims = Jwt::decode($challenge);
        if ($claims === null || ($claims['purpose'] ?? '') !== '2fa') {
            return $this->failUnauthorized('Your verification session expired. Please sign in again.');
        }

        // The challenge may be for a CLIENT logging into their tenant workspace —
        // resolve the user in the right database.
        $tenant = (string) ($claims['tenant'] ?? '') ?: null;
        $users  = new UserModel();
        if ($tenant !== null && preg_match('/^tenant_[a-z0-9_]+$/', $tenant)) {
            try {
                $tdb  = $this->tenantDb($tenant);
                $user = $tdb->query('SELECT * FROM `users` WHERE id = ? LIMIT 1', [(int) ($claims['sub'] ?? 0)])->getRowArray();
                $tdb->close();
            } catch (\Throwable $e) {
                return $this->failUnauthorized('Account unavailable. Please sign in again.');
            }
        } else {
            $tenant = null;
            $user   = $users->find((int) ($claims['sub'] ?? 0));
        }
        if ($user === null || (int) ($user['active'] ?? 1) !== 1) {
            return $this->failUnauthorized('Account unavailable. Please sign in again.');
        }

        if (! Totp::verify((string) ($user['twofa_secret'] ?? ''), $code)) {
            return $this->failUnauthorized('Incorrect verification code.');
        }

        $this->stampTenantLogin($tenant);

        return $this->respond($this->session($users, $user, $tenant));
    }

    /** GET /api/auth/me  (Authorization: Bearer <jwt>) — also the liveness/active check. */
    public function me()
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->failUnauthorized('Not authenticated.');
        }
        if ((int) ($user['active'] ?? 1) !== 1) {
            // Drives real-time forced logout: a deactivated user's session dies
            // on its next poll.
            return $this->failForbidden('Your account has been deactivated.');
        }

        return $this->respond(['user' => (new UserModel())->publicUser($user)]);
    }

    /** POST /api/auth/logout — stateless: the client discards the token. */
    public function logout()
    {
        return $this->respond(['status' => 'ok']);
    }

    // ---- Two-step verification (self-service, authenticated) ----

    /** POST /api/auth/2fa/setup — generate a secret + QR URI (not yet enabled). */
    public function twofaSetup()
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->failUnauthorized('Not authenticated.');
        }

        $secret = Totp::generateSecret();
        (new UserModel())->update($user['id'], ['twofa_secret' => $secret, 'twofa_enabled' => 0]);

        return $this->respond([
            'secret'      => $secret,
            'otpauth_uri' => Totp::uri($secret, $user['email'], self::ISSUER),
        ]);
    }

    /** POST /api/auth/2fa/enable  { code } — confirm setup with a code. */
    public function twofaEnable()
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->failUnauthorized('Not authenticated.');
        }
        $code = (string) (($this->request->getJSON(true) ?? [])['code'] ?? '');

        if (empty($user['twofa_secret']) || ! Totp::verify((string) $user['twofa_secret'], $code)) {
            return $this->failValidationErrors('That code is incorrect. Try again with a fresh code.');
        }
        (new UserModel())->update($user['id'], ['twofa_enabled' => 1]);

        return $this->respond(['twofa_enabled' => true]);
    }

    /** POST /api/auth/2fa/disable — turn off 2FA for the current user. */
    public function twofaDisable()
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->failUnauthorized('Not authenticated.');
        }
        (new UserModel())->update($user['id'], ['twofa_enabled' => 0, 'twofa_secret' => null]);

        return $this->respond(['twofa_enabled' => false]);
    }

    // ---- helpers ----

    /** Issue a session token + public user payload. A tenant client's token
     *  carries its `tenant` claim so every later request routes to its DB. */
    private function session(UserModel $users, array $user, ?string $tenant = null): array
    {
        $claims = [
            'sub'      => (int) $user['id'],
            'name'     => $user['name'],
            'username' => $user['username'],
            'email'    => $user['email'],
            'role'     => $user['role'] ?? 'Member',
        ];
        if ($tenant !== null) {
            $claims['tenant'] = $tenant;
        }

        return ['token' => Jwt::encode($claims), 'user' => $users->publicUser($user)];
    }

    /** Open a connection to a specific tenant database (default credentials). */
    private function tenantDb(string $dbName): \CodeIgniter\Database\BaseConnection
    {
        $cfg             = (array) (new \Config\Database())->default;
        $cfg['database'] = $dbName;
        $cfg['DBDebug']  = false;

        return \Config\Database::connect($cfg, false);
    }

    /**
     * Resolve a login that isn't in the platform (default) database to a client
     * tenant workspace. Fast path: the tenants registry's admin_email; fallback:
     * scan the tenant databases for a user with that email/username.
     *
     * @return array{db:string,user:array}|null
     */
    private function findTenantUser(string $identifier): ?array
    {
        $root  = \Config\Database::connect();
        $ident = strtolower($identifier);

        $candidates = [];
        // 1. Registry fast path — the client admin logs in with their admin_email.
        try {
            $rows = $root->query(
                'SELECT db_name FROM `tenants` WHERE LOWER(admin_email) = ? AND active = 1',
                [$ident],
            )->getResultArray();
            foreach ($rows as $r) {
                $candidates[] = (string) $r['db_name'];
            }
        } catch (\Throwable $e) {
            // registry missing — fall through to a scan
        }
        // 2. Fallback — scan every tenant database for a matching user.
        if ($candidates === []) {
            try {
                foreach ($root->query("SHOW DATABASES LIKE 'tenant\\_%'")->getResultArray() as $r) {
                    $candidates[] = array_values($r)[0];
                }
            } catch (\Throwable $e) {
                return null;
            }
        }

        foreach ($candidates as $dbName) {
            if (! preg_match('/^tenant_[a-z0-9_]+$/', (string) $dbName)) {
                continue;
            }
            try {
                $tdb  = $this->tenantDb($dbName);
                // Case-insensitive so a client matches their account regardless of
                // how they type their email/username (emails aren't case-sensitive).
                $user = $tdb->query(
                    'SELECT * FROM `users` WHERE LOWER(email) = ? OR LOWER(username) = ? LIMIT 1',
                    [$ident, $ident],
                )->getRowArray();
                $tdb->close();
                if ($user !== null) {
                    return ['db' => $dbName, 'user' => $user];
                }
            } catch (\Throwable $e) {
                // db unreachable / no users table — try the next candidate
            }
        }

        return null;
    }

    /** Stamp a tenant's last (real) client login in the registry. No-op for
     *  platform logins and super-admin impersonation. */
    private function stampTenantLogin(?string $dbName): void
    {
        if ($dbName === null) {
            return;
        }
        try {
            \Config\Database::connect()->query(
                'UPDATE `tenants` SET last_login_at = NOW() WHERE db_name = ?',
                [$dbName],
            );
        } catch (\Throwable $e) {
            // registry/column missing — ignore
        }
    }

    private function currentUser(): ?array
    {
        $header = $this->request->getHeaderLine('Authorization');
        $token  = preg_match('/Bearer\s+(.+)/i', $header, $m) ? trim($m[1]) : '';
        if ($token === '') {
            return null;
        }
        $claims = Jwt::decode($token);
        if ($claims === null) {
            return null;
        }

        return (new UserModel())->find((int) ($claims['sub'] ?? 0));
    }
}
