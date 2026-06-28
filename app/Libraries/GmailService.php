<?php

namespace App\Libraries;

/**
 * Minimal Gmail API client (OAuth2 + REST) using cURL.
 *
 * Receives the inbox (gmail.readonly) and sends mail (gmail.send) on behalf of
 * the signed-in CRM user. Tokens are stored per user under writable/gmail/.
 *
 * Requires a Google Cloud OAuth client. Set in .env:
 *   google.clientId, google.clientSecret, google.redirectUri
 */
class GmailService
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    /** Reason the last OAuth code exchange failed (e.g. invalid_client), for diagnostics. */
    private string $lastError = '';

    private const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO  = 'https://www.googleapis.com/oauth2/v2/userinfo';
    private const GMAIL      = 'https://gmail.googleapis.com/gmail/v1/users/me';
    private const CALENDAR  = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';
    private const SCOPES    = 'https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/calendar.events';

    public function __construct()
    {
        $cfg                = $this->loadConfig();
        $this->clientId     = $cfg['clientId'];
        $this->clientSecret = $cfg['clientSecret'];
        $this->redirectUri  = $cfg['redirectUri'];
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    // ---------- OAuth app config (stored in the DB, .env as fallback) ----------

    private const SETTINGS_KEY = 'gmail_oauth';

    /**
     * Effective config: the DB row wins. On the main/platform DB it falls back to
     * the .env google.* defaults — but on a CLIENT database (tenant_*) it does
     * NOT: each client must use their own Google OAuth app, never the platform's.
     * The redirect URI defaults to this deployment's own callback when unset.
     */
    private function loadConfig(): array
    {
        $saved    = Settings::get(self::SETTINGS_KEY) ?? [];
        $onTenant = $this->onTenantDb();

        $envId     = $onTenant ? '' : (string) (env('google.clientId') ?? '');
        $envSecret = $onTenant ? '' : (string) (env('google.clientSecret') ?? '');
        $envRedir  = $onTenant ? '' : (string) (env('google.redirectUri') ?? '');

        $redirect = (string) ($saved['redirectUri'] ?? $envRedir);
        if ($redirect === '') {
            // Same callback for every client; each registers it in their own
            // Google Cloud project. Derived from app.baseURL so it's correct per
            // deployment (e.g. https://crm.example.com/api/gmail/callback).
            $redirect = rtrim((string) base_url('gmail/callback'), '/');
        }

        return [
            'clientId'     => (string) ($saved['clientId']     ?? $envId),
            'clientSecret' => (string) ($saved['clientSecret'] ?? $envSecret),
            'redirectUri'  => $redirect,
        ];
    }

    /** True when the active DB connection points at a client (tenant_*) database. */
    private function onTenantDb(): bool
    {
        return str_starts_with((string) (config('Database')->default['database'] ?? ''), 'tenant_');
    }

    /** Persist the OAuth app credentials from the admin UI. Secret kept if blank. */
    public function saveConfig(array $cfg): void
    {
        $current = $this->loadConfig();
        $merged  = [
            'clientId'     => trim((string) ($cfg['clientId'] ?? $current['clientId'])),
            'clientSecret' => trim((string) ($cfg['clientSecret'] ?? '')) !== '' ? trim((string) $cfg['clientSecret']) : $current['clientSecret'],
            'redirectUri'  => trim((string) ($cfg['redirectUri'] ?? '')) !== '' ? trim((string) $cfg['redirectUri']) : $current['redirectUri'],
        ];
        Settings::set(self::SETTINGS_KEY, $merged);
        $this->clientId     = $merged['clientId'];
        $this->clientSecret = $merged['clientSecret'];
        $this->redirectUri  = $merged['redirectUri'];
    }

    /** Non-secret view of the config for the admin UI. */
    public function publicConfig(): array
    {
        return [
            'configured'  => $this->isConfigured(),
            'clientId'    => $this->clientId, // a Client ID is not a secret
            'redirectUri' => $this->redirectUri,
            'hasSecret'   => $this->clientSecret !== '',
        ];
    }

    /**
     * Full diagnostic view (no secret value) so an operator can verify what the
     * server is actually using in production. `secretTail` is the last 5 chars
     * only — enough to tell WHICH secret is loaded without exposing it.
     */
    public function diagnostics(): array
    {
        $saved = Settings::get(self::SETTINGS_KEY);
        $fromDb = is_array($saved) && ($saved['clientId'] ?? '') !== '';

        return [
            'configured'    => $this->isConfigured(),
            'source'        => $fromDb ? 'database (settings table — overrides .env)' : '.env fallback',
            'clientId'      => $this->clientId,
            'redirectUri'   => $this->redirectUri,
            'hasSecret'     => $this->clientSecret !== '',
            'secretTail'    => $this->clientSecret !== '' ? '…' . substr($this->clientSecret, -5) : '(none)',
            'frontendUrl'   => (string) (env('app.frontendUrl') ?? ''),
            'baseUrl'       => (string) (env('app.baseURL') ?? ''),
            'scopes'        => self::SCOPES,
            'expectedRedirectForThisDomain' => 'add this exact value to Google Cloud → Authorized redirect URIs',
        ];
    }

    // ---------- OAuth ----------

    public function authUrl(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => self::SCOPES,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'include_granted_scopes' => 'true',
            'state'         => $state,
        ]);
    }

    /** The reason the last exchangeCode() failed (Google error code), if any. */
    public function lastError(): string
    {
        return $this->lastError;
    }

    /** Exchange an authorization code for tokens and persist them for $userId. */
    public function exchangeCode(string $userId, string $code): bool
    {
        $res = $this->post(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->redirectUri,
            'grant_type'    => 'authorization_code',
        ], false);
        if (!isset($res['access_token'])) {
            // Surface the real cause (invalid_client, redirect_uri_mismatch, …) so
            // the callback can report it instead of a silent failure.
            $this->lastError = (string) ($res['error'] ?? 'token_exchange_failed');
            log_message('error', 'Gmail OAuth exchange failed: {err} {desc}', [
                'err'  => $this->lastError,
                'desc' => (string) ($res['error_description'] ?? ''),
            ]);
            return false;
        }
        $tokens = [
            'access_token'  => $res['access_token'],
            'refresh_token' => $res['refresh_token'] ?? ($this->tokens($userId)['refresh_token'] ?? ''),
            'expires_at'    => time() + (int) ($res['expires_in'] ?? 3500),
            'email'         => '',
        ];
        $this->save($userId, $tokens);
        // Fetch the connected email.
        $profile = $this->get(self::USERINFO, $userId);
        if (isset($profile['email'])) {
            $tokens['email'] = $profile['email'];
            $this->save($userId, $tokens);
        }
        return true;
    }

    public function status(string $userId): array
    {
        $t = $this->tokens($userId);
        return [
            'configured' => $this->isConfigured(),
            'connected'  => !empty($t['refresh_token']) || !empty($t['access_token']),
            'email'      => $t['email'] ?? '',
        ];
    }

    public function disconnect(string $userId): void
    {
        $path = $this->path($userId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    // ---------- Mail ----------

    /** List the latest inbox messages with light metadata. */
    public function listInbox(string $userId, int $max = 20): array
    {
        $list = $this->get(self::GMAIL . '/messages?' . http_build_query(['q' => 'in:inbox', 'maxResults' => $max]), $userId);
        $out  = [];
        foreach (($list['messages'] ?? []) as $m) {
            $full = $this->get(self::GMAIL . '/messages/' . $m['id'] . '?' . http_build_query([
                'format'             => 'metadata',
                'metadataHeaders'    => 'From',
            ]) . '&metadataHeaders=Subject&metadataHeaders=Date', $userId);
            $headers = [];
            foreach (($full['payload']['headers'] ?? []) as $h) {
                $headers[strtolower($h['name'])] = $h['value'];
            }
            $out[] = [
                'id'      => $m['id'],
                'from'    => $headers['from'] ?? '',
                'subject' => $headers['subject'] ?? '(no subject)',
                'date'    => $headers['date'] ?? '',
                'snippet' => $full['snippet'] ?? '',
                'unread'  => in_array('UNREAD', $full['labelIds'] ?? [], true),
            ];
        }
        return $out;
    }

    /** Full message with decoded plain-text body. */
    public function getMessage(string $userId, string $id): array
    {
        $full = $this->get(self::GMAIL . '/messages/' . $id . '?format=full', $userId);
        $headers = [];
        foreach (($full['payload']['headers'] ?? []) as $h) {
            $headers[strtolower($h['name'])] = $h['value'];
        }
        return [
            'id'      => $id,
            'from'    => $headers['from'] ?? '',
            'to'      => $headers['to'] ?? '',
            'subject' => $headers['subject'] ?? '(no subject)',
            'date'    => $headers['date'] ?? '',
            'body'    => $this->extractBody($full['payload'] ?? []),
        ];
    }

    /** @return array{ok: bool, error?: string} */
    public function send(string $userId, string $to, string $subject, string $body): array
    {
        if (empty($this->tokens($userId)['refresh_token']) && empty($this->tokens($userId)['access_token'])) {
            return ['ok' => false, 'error' => 'Gmail is not connected. Click Connect Gmail first.'];
        }
        if ($this->accessToken($userId) === '') {
            return ['ok' => false, 'error' => 'Could not obtain an access token — the Gmail connection expired. Reconnect Gmail.'];
        }
        $me  = $this->status($userId)['email'] ?: 'me';
        $raw = "From: {$me}\r\nTo: {$to}\r\nSubject: {$subject}\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$body}";
        $res = $this->post(self::GMAIL . '/messages/send', ['raw' => $this->base64url($raw)], true, $userId);
        if (isset($res['id'])) {
            return ['ok' => true];
        }
        $msg = $res['error']['message'] ?? 'The Gmail API rejected the message.';
        // The most common cause: the connected token lacks the gmail.send scope.
        if (stripos($msg, 'insufficient') !== false || stripos($msg, 'scope') !== false) {
            $msg .= ' — Disconnect and reconnect Gmail to grant send permission.';
        }
        return ['ok' => false, 'error' => $msg];
    }

    // ---------- Google Calendar ----------

    /**
     * Events from the user's primary Google Calendar within an optional window.
     * $timeMin / $timeMax are RFC3339 timestamps; defaults to "now onwards".
     */
    public function listCalendarEvents(string $userId, int $max = 20, string $timeMin = '', string $timeMax = ''): array
    {
        $params = [
            'timeMin'      => $timeMin !== '' ? $timeMin : gmdate('c'),
            'maxResults'   => $max,
            'singleEvents' => 'true',
            'orderBy'      => 'startTime',
        ];
        if ($timeMax !== '') {
            $params['timeMax'] = $timeMax;
        }
        $url = self::CALENDAR . '?' . http_build_query($params);
        $res = $this->get($url, $userId);
        $out = [];
        foreach (($res['items'] ?? []) as $e) {
            $out[] = [
                'id'          => $e['id'] ?? '',
                'summary'     => $e['summary'] ?? '(no title)',
                'start'       => $e['start']['dateTime'] ?? $e['start']['date'] ?? '',
                'end'         => $e['end']['dateTime'] ?? $e['end']['date'] ?? '',
                'allDay'      => empty($e['start']['dateTime']),
                'meetLink'    => $e['hangoutLink'] ?? $this->meetFromConference($e),
                'htmlLink'    => $e['htmlLink'] ?? '',
                'location'    => $e['location'] ?? '',
                'description' => $e['description'] ?? '',
                'organizer'   => $e['organizer']['email'] ?? '',
                'status'      => $e['status'] ?? '',
                'attendees'   => array_values(array_filter(array_map(static fn ($a) => (string) ($a['email'] ?? ''), $e['attendees'] ?? []))),
            ];
        }

        return $out;
    }

    /**
     * Create a Google Calendar event with a Google Meet link.
     * $data: { summary, description?, start (ISO), end (ISO), attendees? (emails[]) }
     */
    public function createCalendarEvent(string $userId, array $data): array
    {
        $body = [
            'summary'     => (string) ($data['summary'] ?? 'Meeting'),
            'description' => (string) ($data['description'] ?? ''),
            'start'       => ['dateTime' => (string) ($data['start'] ?? gmdate('c'))],
            'end'         => ['dateTime' => (string) ($data['end'] ?? gmdate('c', time() + 1800))],
            'conferenceData' => [
                'createRequest' => [
                    'requestId'             => bin2hex(random_bytes(8)),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ],
        ];
        if (! empty($data['attendees']) && is_array($data['attendees'])) {
            $body['attendees'] = array_map(static fn ($e) => ['email' => $e], $data['attendees']);
        }
        $res = $this->post(self::CALENDAR . '?conferenceDataVersion=1', $body, true, $userId);

        return [
            'id'       => $res['id'] ?? '',
            'summary'  => $res['summary'] ?? $body['summary'],
            'start'    => $res['start']['dateTime'] ?? '',
            'meetLink' => $res['hangoutLink'] ?? $this->meetFromConference($res),
            'htmlLink' => $res['htmlLink'] ?? '',
            'created'  => isset($res['id']),
        ];
    }

    private function meetFromConference(array $event): string
    {
        foreach (($event['conferenceData']['entryPoints'] ?? []) as $ep) {
            if (($ep['entryPointType'] ?? '') === 'video') {
                return (string) ($ep['uri'] ?? '');
            }
        }

        return '';
    }

    // ---------- internals ----------

    private function extractBody(array $payload): string
    {
        if (!empty($payload['body']['data'])) {
            return $this->base64urlDecode($payload['body']['data']);
        }
        foreach (($payload['parts'] ?? []) as $part) {
            if (($part['mimeType'] ?? '') === 'text/plain' && !empty($part['body']['data'])) {
                return $this->base64urlDecode($part['body']['data']);
            }
        }
        foreach (($payload['parts'] ?? []) as $part) {
            $nested = $this->extractBody($part);
            if ($nested !== '') {
                return $nested;
            }
        }
        return '';
    }

    /** Returns a valid access token, refreshing if it has expired. */
    private function accessToken(string $userId): string
    {
        $t = $this->tokens($userId);
        if (empty($t)) {
            return '';
        }
        if (!empty($t['access_token']) && ($t['expires_at'] ?? 0) > time() + 30) {
            return $t['access_token'];
        }
        if (empty($t['refresh_token'])) {
            return (string) ($t['access_token'] ?? '');
        }
        $res = $this->post(self::TOKEN_URL, [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $t['refresh_token'],
            'grant_type'    => 'refresh_token',
        ], false);
        if (isset($res['access_token'])) {
            $t['access_token'] = $res['access_token'];
            $t['expires_at']   = time() + (int) ($res['expires_in'] ?? 3500);
            $this->save($userId, $t);
            return $t['access_token'];
        }
        return (string) ($t['access_token'] ?? '');
    }

    private function get(string $url, string $userId): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->accessToken($userId)],
            CURLOPT_TIMEOUT        => 20,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        return is_string($body) ? (json_decode($body, true) ?: []) : [];
    }

    private function post(string $url, array $data, bool $json, string $userId = ''): array
    {
        $ch = curl_init($url);
        $headers = [];
        if ($json) {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken($userId);
            $headers[] = 'Content-Type: application/json';
            $payload = json_encode($data);
        } else {
            $payload = http_build_query($data);
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        return is_string($body) ? (json_decode($body, true) ?: []) : [];
    }

    private function path(string $userId): string
    {
        $dir = WRITEPATH . 'gmail';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $userId) . '.json';
    }

    private function tokens(string $userId): array
    {
        $path = $this->path($userId);
        return is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
    }

    private function save(string $userId, array $tokens): void
    {
        file_put_contents($this->path($userId), json_encode($tokens));
    }

    private function base64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private function base64urlDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'));
    }
}
