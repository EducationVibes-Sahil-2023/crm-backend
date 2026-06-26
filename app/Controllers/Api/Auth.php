<?php

namespace App\Controllers\Api;

use App\Libraries\Jwt;
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

        $users = new UserModel();
        $user  = $users->findByIdentifier($identifier);

        if ($user === null || ! password_verify($password, $user['password'])) {
            return $this->failUnauthorized('Invalid email or password.');
        }

        if ((int) ($user['active'] ?? 1) !== 1) {
            return $this->failForbidden('Your account has been deactivated. Please contact an administrator.');
        }

        // Two-step verification: hand back a short-lived challenge instead of a
        // session token. The client then posts the authenticator code to /verify.
        if ((int) ($user['twofa_enabled'] ?? 0) === 1) {
            $challenge = Jwt::encode(['sub' => (int) $user['id'], 'purpose' => '2fa'], 300);

            return $this->respond([
                'twofa_required' => true,
                'challenge'      => $challenge,
                'name'           => $user['name'],
            ]);
        }

        return $this->respond($this->session($users, $user));
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

        $users = new UserModel();
        $user  = $users->find((int) ($claims['sub'] ?? 0));
        if ($user === null || (int) ($user['active'] ?? 1) !== 1) {
            return $this->failUnauthorized('Account unavailable. Please sign in again.');
        }

        if (! Totp::verify((string) ($user['twofa_secret'] ?? ''), $code)) {
            return $this->failUnauthorized('Incorrect verification code.');
        }

        return $this->respond($this->session($users, $user));
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

    /** Issue a session token + public user payload. */
    private function session(UserModel $users, array $user): array
    {
        $token = Jwt::encode([
            'sub'      => (int) $user['id'],
            'name'     => $user['name'],
            'username' => $user['username'],
            'email'    => $user['email'],
            'role'     => $user['role'] ?? 'Member',
        ]);

        return ['token' => $token, 'user' => $users->publicUser($user)];
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
