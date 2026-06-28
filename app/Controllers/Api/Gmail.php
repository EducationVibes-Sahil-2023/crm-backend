<?php

namespace App\Controllers\Api;

use App\Libraries\GmailService;
use App\Libraries\Jwt;
use CodeIgniter\RESTful\ResourceController;

/**
 * Real Gmail integration: OAuth connect, read inbox, send mail.
 *
 * All endpoints except callback() run behind the JWT `auth` filter, which sets
 * $request->jwtUserId. Tokens are stored per user by GmailService. callback()
 * is hit by Google's redirect (no bearer), so it recovers the user from `state`.
 */
class Gmail extends ResourceController
{
    protected $format = 'json';

    private function gmail(): GmailService
    {
        // Make sure the per-client (tenant) database is selected before the
        // service reads its OAuth credentials, so each client uses their OWN
        // Google app — never the platform's.
        $this->applyTenantDb($this->tenant());
        return new GmailService();
    }

    /** Decoded JWT claims for this request (filter-set, else decoded from the bearer). */
    private function claims(): array
    {
        $header = $this->request->getHeaderLine('Authorization');
        if (preg_match('/Bearer\s+(.+)/i', $header, $m)) {
            $c = Jwt::decode(trim($m[1]));
            if (is_array($c)) {
                return $c;
            }
        }
        return [];
    }

    /**
     * The connecting user's id. Normally set by the JWT `auth` filter
     * ($request->jwtUserId). Falls back to decoding the Bearer token directly —
     * so this works even on deployments where the filter's URI patterns don't
     * match the routes (e.g. the API is mounted under a subfolder). Without this,
     * authUrl() would build a state with an empty user id and the callback would
     * fail with reason=no_user ("sign-in didn't complete").
     */
    private function userId(): string
    {
        $id = (string) ($this->request->jwtUserId ?? '');
        return $id !== '' ? $id : (string) ($this->claims()['sub'] ?? '');
    }

    /** The client workspace database this request belongs to (tenant_<slug>), if any. */
    private function tenant(): string
    {
        $t = (string) ($this->request->jwtTenant ?? '');
        return $t !== '' ? $t : (string) ($this->claims()['tenant'] ?? '');
    }

    /**
     * Token-storage key, namespaced by tenant — so client A's user #5 and client
     * B's user #5 never share a Google connection. Plain user id on the main DB.
     */
    private function scope(): string
    {
        $t = $this->tenant();
        return $t !== '' ? $t . '__' . $this->userId() : $this->userId();
    }

    /**
     * Point the default DB connection at the client's tenant database, so models
     * (and the OAuth credential lookup) run against that client's isolated data.
     * Mirrors the JwtAuth filter — needed here too because the bearer-less OAuth
     * callback never runs the filter, and some deployments mount the API under a
     * subfolder where the filter's URI patterns don't match.
     */
    private function applyTenantDb(string $tenant): void
    {
        if ($tenant !== '' && preg_match('/^tenant_[a-z0-9_]+$/', $tenant)) {
            config('Database')->default['database'] = $tenant;
        }
    }

    /** GET /api/gmail/status -> { configured, connected, email } */
    public function status()
    {
        return $this->respond($this->gmail()->status($this->scope()));
    }

    /** GET /api/gmail/config -> { configured, clientId, redirectUri, hasSecret } */
    public function getConfig()
    {
        return $this->respond($this->gmail()->publicConfig());
    }

    /**
     * GET /api/gmail/diagnose?key=<setup.key>
     * Key-guarded (no JWT). Shows the effective OAuth config the server uses —
     * which secret (last 5 chars only), redirect URI, DB vs .env source — so you
     * can verify production from a browser without reading logs. No secret leaks.
     */
    public function diagnose()
    {
        $key      = (string) ($this->request->getGet('key') ?? '');
        $expected = (string) (env('setup.key') ?: '');
        if ($expected === '' || ! hash_equals($expected, $key)) {
            return $this->failUnauthorized('Invalid or missing key.');
        }

        return $this->respond($this->gmail()->diagnostics());
    }

    /**
     * GET /api/gmail/set-config?key=<setup.key>&clientId=...&clientSecret=...&redirectUri=...
     * Key-guarded (no JWT) way to write the OAuth app credentials straight to the
     * DB, bypassing the super-admin console when its Save can't work. Any param
     * left out keeps its current value. Returns the resulting diagnostics so you
     * see immediately that it took. (One-time admin fix — rotate the secret after
     * if you're concerned about it appearing in server logs.)
     */
    public function setConfig()
    {
        $key      = (string) ($this->request->getGet('key') ?? '');
        $expected = (string) (env('setup.key') ?: '');
        if ($expected === '' || ! hash_equals($expected, $key)) {
            return $this->failUnauthorized('Invalid or missing key.');
        }

        $svc = $this->gmail();
        $svc->saveConfig([
            'clientId'     => trim((string) ($this->request->getGet('clientId') ?? '')),
            'clientSecret' => trim((string) ($this->request->getGet('clientSecret') ?? '')),
            'redirectUri'  => trim((string) ($this->request->getGet('redirectUri') ?? '')),
        ]);

        return $this->respond($svc->diagnostics());
    }

    /** POST /api/gmail/config { clientId, clientSecret?, redirectUri? } — admin sets the OAuth app. */
    public function saveConfig()
    {
        $in  = $this->request->getJSON(true) ?: [];
        $svc = $this->gmail();
        $svc->saveConfig([
            'clientId'     => $in['clientId'] ?? '',
            'clientSecret' => $in['clientSecret'] ?? '',
            'redirectUri'  => $in['redirectUri'] ?? '',
        ]);
        return $this->respond($svc->publicConfig());
    }

    /** GET /api/gmail/auth-url -> { url } */
    public function authUrl()
    {
        $svc = $this->gmail();
        if (! $svc->isConfigured()) {
            return $this->fail('Google is not set up for this workspace yet. Add your OAuth Client ID and Secret in Admin Setup → Integrations → Google.', 503);
        }
        // state carries the user id + tenant (so the bearer-less callback knows
        // who connected and which client database to write the token into) and
        // the frontend path to return to after consent.
        $return = $this->safeReturn((string) $this->request->getGet('return'));
        $state  = base64_encode($this->userId() . '|' . bin2hex(random_bytes(8)) . '|' . $return . '|' . $this->tenant());
        return $this->respond(['url' => $svc->authUrl($state)]);
    }

    /** GET /api/gmail/callback?code&state — Google redirect target (no auth). */
    public function callback()
    {
        $code  = (string) $this->request->getGet('code');
        $state = (string) $this->request->getGet('state');
        $front = (string) (env('app.frontendUrl') ?? 'http://localhost:3000');

        $userId = '';
        $tenant = '';
        $return = '/gmail';
        if ($state !== '') {
            $decoded = base64_decode($state, true);
            if ($decoded !== false && str_contains($decoded, '|')) {
                $parts  = explode('|', $decoded);
                $userId = $parts[0];
                $return = $this->safeReturn($parts[2] ?? '');
                $tenant = (string) ($parts[3] ?? '');
            }
        }

        // Re-select the client's database BEFORE the service loads credentials, so
        // the code is exchanged with that client's own Google app, and the token
        // is stored under the tenant-namespaced key.
        $this->applyTenantDb($tenant);
        $scope  = $tenant !== '' ? $tenant . '__' . $userId : $userId;

        $svc    = new GmailService();
        $ok     = $code !== '' && $userId !== '' && $svc->exchangeCode($scope, $code);
        $reason = $ok ? '' : ($svc->lastError() ?: ($code === '' ? 'no_code' : ($userId === '' ? 'no_user' : 'failed')));

        $url = $front . $return . '?connected=' . ($ok ? '1' : '0');
        if ($reason !== '') {
            $url .= '&reason=' . rawurlencode($reason);
        }
        return redirect()->to($url);
    }

    /** Only allow internal app paths as the post-consent redirect (no open redirect). */
    private function safeReturn(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '://')) {
            return '/gmail';
        }
        return $path;
    }

    /** GET /api/gmail/messages -> [ {id, from, subject, date, snippet, unread} ] */
    public function messages()
    {
        $max = (int) ($this->request->getGet('max') ?? 20);
        return $this->respond($this->gmail()->listInbox($this->scope(), max(1, min(50, $max))));
    }

    /** GET /api/gmail/message/$id -> { id, from, to, subject, date, body } */
    public function message($id = null)
    {
        if (! $id) {
            return $this->failValidationErrors('A message id is required.');
        }
        return $this->respond($this->gmail()->getMessage($this->scope(), (string) $id));
    }

    /** POST /api/gmail/send  { to, subject, body } */
    public function send()
    {
        $in = $this->request->getJSON(true);
        $to = trim((string) ($in['to'] ?? ''));
        if ($to === '') {
            return $this->failValidationErrors('A recipient ("to") is required.');
        }
        $res = $this->gmail()->send(
            $this->scope(),
            $to,
            (string) ($in['subject'] ?? ''),
            (string) ($in['body'] ?? ''),
        );
        return $res['ok']
            ? $this->respond(['sent' => true])
            : $this->fail($res['error'] ?? 'Could not send the message. Reconnect Gmail and try again.', 502);
    }

    /** GET /api/gmail/calendar?max&timeMin&timeMax -> Google Calendar events in the window */
    public function calendarEvents()
    {
        $max     = (int) ($this->request->getGet('max') ?? 50);
        $timeMin = (string) ($this->request->getGet('timeMin') ?? '');
        $timeMax = (string) ($this->request->getGet('timeMax') ?? '');
        return $this->respond($this->gmail()->listCalendarEvents($this->scope(), max(1, min(250, $max)), $timeMin, $timeMax));
    }

    /** POST /api/gmail/calendar { summary, start, end, description?, attendees? } — create an event + Meet link. */
    public function createCalendarEvent()
    {
        $in = $this->request->getJSON(true) ?: [];
        if (trim((string) ($in['summary'] ?? '')) === '') {
            return $this->failValidationErrors('A meeting title ("summary") is required.');
        }
        $res = $this->gmail()->createCalendarEvent($this->scope(), $in);
        return ! empty($res['created'])
            ? $this->respondCreated($res)
            : $this->fail('Could not create the calendar event. Reconnect Google and try again.', 502);
    }

    /** POST /api/gmail/disconnect */
    public function disconnect()
    {
        $this->gmail()->disconnect($this->scope());
        return $this->respond(['disconnected' => true]);
    }
}
