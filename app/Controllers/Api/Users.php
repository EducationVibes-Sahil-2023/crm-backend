<?php

namespace App\Controllers\Api;

use App\Libraries\Jwt;
use App\Models\UserModel;
use CodeIgniter\RESTful\ResourceController;

/**
 * Admin management of real login accounts. Backs the "Accounts & Security"
 * panel: create/edit/remove accounts, activate/deactivate (which forces the
 * affected user's session to log out on its next poll), and reset 2FA.
 *
 * Every action requires an authenticated Administrator.
 */
class Users extends ResourceController
{
    protected $modelName = UserModel::class;
    protected $format    = 'json';

    /** GET /api/users */
    public function index()
    {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        $rows = $this->model->orderBy('id', 'ASC')->findAll();

        return $this->respond(array_map(fn ($u) => $this->model->publicUser($u), $rows));
    }

    /** POST /api/users */
    public function create()
    {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        $in = $this->request->getJSON(true) ?? [];

        $name     = trim((string) ($in['name'] ?? ''));
        $email    = strtolower(trim((string) ($in['email'] ?? '')));
        $username = trim((string) ($in['username'] ?? '')) ?: $this->usernameFrom($email);
        $password = (string) ($in['password'] ?? '');

        if ($name === '' || $email === '' || strlen($password) < 6) {
            return $this->failValidationErrors('Name, email and a password (min 6 chars) are required.');
        }
        if ($this->model->where('email', $email)->first() !== null) {
            return $this->failValidationErrors('An account with that email already exists.');
        }
        if ($this->model->where('username', $username)->first() !== null) {
            return $this->failValidationErrors('That username is taken.');
        }

        $id = $this->model->insert([
            'name'        => $name,
            'username'    => $username,
            'email'       => $email,
            'password'    => password_hash($password, PASSWORD_DEFAULT),
            'role'        => (string) ($in['role'] ?? 'Member'),
            'active'      => isset($in['active']) ? (int) (bool) $in['active'] : 1,
            'phone'       => $this->nn($in['phone'] ?? null),
            'department'  => $this->nn($in['department'] ?? null),
            'designation' => $this->nn($in['designation'] ?? null),
            'avatar'      => $this->nn($in['avatar'] ?? null),
        ], true);

        return $this->respondCreated($this->model->publicUser($this->model->find($id)));
    }

    /** PUT /api/users/{id} */
    public function update($id = null)
    {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        $user = $this->model->find($id);
        if ($user === null) {
            return $this->failNotFound("Account #{$id} not found");
        }
        $in   = $this->request->getJSON(true) ?? $this->request->getRawInput();
        $data = [];

        foreach (['name', 'role', 'phone', 'department', 'designation', 'avatar'] as $f) {
            if (array_key_exists($f, $in)) {
                $data[$f] = $this->nn($in[$f]);
            }
        }
        if (array_key_exists('active', $in)) {
            $data['active'] = (int) (bool) $in['active'];
        }
        if (! empty($in['email'])) {
            $email = strtolower(trim((string) $in['email']));
            $clash = $this->model->where('email', $email)->where('id !=', $id)->first();
            if ($clash !== null) {
                return $this->failValidationErrors('Another account already uses that email.');
            }
            $data['email'] = $email;
        }
        if (! empty($in['username'])) {
            $username = trim((string) $in['username']);
            $clash    = $this->model->where('username', $username)->where('id !=', $id)->first();
            if ($clash !== null) {
                return $this->failValidationErrors('That username is taken.');
            }
            $data['username'] = $username;
        }
        if (! empty($in['password'])) {
            if (strlen((string) $in['password']) < 6) {
                return $this->failValidationErrors('Password must be at least 6 characters.');
            }
            $data['password'] = password_hash((string) $in['password'], PASSWORD_DEFAULT);
        }

        if ($data === []) {
            return $this->failValidationErrors('Nothing to update.');
        }
        $this->model->update($id, $data);

        return $this->respond($this->model->publicUser($this->model->find($id)));
    }

    /** DELETE /api/users/{id} */
    public function delete($id = null)
    {
        if (($guard = $this->requireAdmin($me)) !== null) {
            return $guard;
        }
        if ($this->model->find($id) === null) {
            return $this->failNotFound("Account #{$id} not found");
        }
        if ((int) $id === (int) $me['id']) {
            return $this->fail('You cannot delete your own account.', 409);
        }
        $this->model->delete($id);

        return $this->respondDeleted(['id' => (int) $id]);
    }

    /** POST /api/users/{id}/activate */
    public function activate($id = null)
    {
        return $this->setActive($id, 1);
    }

    /** POST /api/users/{id}/deactivate */
    public function deactivate($id = null)
    {
        return $this->setActive($id, 0);
    }

    /** POST /api/users/{id}/reset-2fa — admin clears a user's 2FA. */
    public function resetTwofa($id = null)
    {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }
        if ($this->model->find($id) === null) {
            return $this->failNotFound("Account #{$id} not found");
        }
        $this->model->update($id, ['twofa_enabled' => 0, 'twofa_secret' => null]);

        return $this->respond($this->model->publicUser($this->model->find($id)));
    }

    // ---- helpers ----

    private function setActive($id, int $active)
    {
        if (($guard = $this->requireAdmin($me)) !== null) {
            return $guard;
        }
        if ($this->model->find($id) === null) {
            return $this->failNotFound("Account #{$id} not found");
        }
        if ((int) $id === (int) $me['id'] && $active === 0) {
            return $this->fail('You cannot deactivate your own account.', 409);
        }
        $this->model->update($id, ['active' => $active]);

        return $this->respond($this->model->publicUser($this->model->find($id)));
    }

    /**
     * Returns an error Response if the caller is not an admin, else null.
     * Sets $me to the caller's record by reference.
     */
    private function requireAdmin(&$me = null)
    {
        $header = $this->request->getHeaderLine('Authorization');
        $token  = preg_match('/Bearer\s+(.+)/i', $header, $mm) ? trim($mm[1]) : '';
        $claims = $token !== '' ? Jwt::decode($token) : null;

        if ($claims === null) {
            return $this->failUnauthorized('Not authenticated.');
        }

        // Platform super-admin (sub: super-admin) provisions client login accounts
        // directly from the console — it isn't a row in the users table.
        if (($claims['sub'] ?? '') === 'super-admin' || ($claims['role'] ?? '') === 'super-admin') {
            $me = ['id' => 0, 'name' => 'Super Admin', 'role' => 'Administrator'];

            return null;
        }

        $me = $this->model->find((int) ($claims['sub'] ?? 0));
        if ($me === null) {
            return $this->failUnauthorized('Not authenticated.');
        }
        if (strtolower((string) ($me['role'] ?? '')) !== 'administrator') {
            return $this->failForbidden('Only administrators can manage accounts.');
        }

        return null;
    }

    private function nn($v): ?string
    {
        $v = is_string($v) ? trim($v) : $v;

        return ($v === '' || $v === null) ? null : (string) $v;
    }

    private function usernameFrom(string $email): string
    {
        $base = preg_replace('/[^a-z0-9._-]/', '', strtolower(explode('@', $email)[0] ?? 'user')) ?: 'user';
        $name = $base;
        $i    = 1;
        while ($this->model->where('username', $name)->first() !== null) {
            $name = $base . $i++;
        }

        return $name;
    }
}
