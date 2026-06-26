<?php

namespace App\Libraries;

use Config\Services;

/**
 * SMTP relay config + sender. A writable file (writable/mail/smtp.json) holds
 * the credentials set from the admin UI and overrides any .env defaults; mail
 * goes out through CodeIgniter's Email library.
 *
 * This is the simple alternative to the Gmail OAuth path: any SMTP provider
 * (Gmail app password, SendGrid, Mailgun, Zoho, your own server…) works.
 */
class SmtpService
{
    private const SETTINGS_KEY = 'smtp';

    /** Effective config: the DB row wins, falling back to .env. */
    public function loadConfig(): array
    {
        $saved = Settings::get(self::SETTINGS_KEY) ?? [];
        return [
            'host'       => (string) ($saved['host']       ?? (env('smtp.host') ?? '')),
            'port'       => (int)    ($saved['port']       ?? (env('smtp.port') ?? 587)),
            'username'   => (string) ($saved['username']   ?? (env('smtp.username') ?? '')),
            'password'   => (string) ($saved['password']   ?? (env('smtp.password') ?? '')),
            'encryption' => (string) ($saved['encryption'] ?? (env('smtp.encryption') ?? 'tls')), // tls | ssl | none
            'fromName'   => (string) ($saved['fromName']   ?? (env('smtp.fromName') ?? 'CRM')),
            'fromEmail'  => (string) ($saved['fromEmail']  ?? (env('smtp.fromEmail') ?? '')),
        ];
    }

    /** Persist config from the admin UI. Password kept if sent blank. */
    public function saveConfig(array $in): void
    {
        $current = $this->loadConfig();
        $merged  = [
            'host'       => trim((string) ($in['host'] ?? $current['host'])),
            'port'       => (int) ($in['port'] ?? $current['port']) ?: 587,
            'username'   => trim((string) ($in['username'] ?? $current['username'])),
            'password'   => trim((string) ($in['password'] ?? '')) !== '' ? (string) $in['password'] : $current['password'],
            'encryption' => in_array(($in['encryption'] ?? ''), ['tls', 'ssl', 'none'], true) ? $in['encryption'] : $current['encryption'],
            'fromName'   => trim((string) ($in['fromName'] ?? $current['fromName'])),
            'fromEmail'  => trim((string) ($in['fromEmail'] ?? $current['fromEmail'])),
        ];
        Settings::set(self::SETTINGS_KEY, $merged);
    }

    public function isConfigured(): bool
    {
        $c = $this->loadConfig();
        return $c['host'] !== '' && $c['fromEmail'] !== '';
    }

    /** Non-secret view for the admin UI. */
    public function publicConfig(): array
    {
        $c = $this->loadConfig();
        unset($c['password']);
        return $c + ['configured' => $this->isConfigured(), 'hasPassword' => $this->loadConfig()['password'] !== ''];
    }

    /**
     * Send an email through the configured relay.
     * @return array{ok: bool, error?: string}
     */
    public function send(string $to, string $subject, string $body, bool $html = false): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'SMTP is not configured. Set the host and From address first.'];
        }
        if (trim($to) === '') {
            return ['ok' => false, 'error' => 'A recipient is required.'];
        }

        $c     = $this->loadConfig();
        $email = Services::email();
        $email->initialize([
            'protocol'    => 'smtp',
            'SMTPHost'    => $c['host'],
            'SMTPUser'    => $c['username'],
            'SMTPPass'    => $c['password'],
            'SMTPPort'    => $c['port'],
            'SMTPCrypto'  => $c['encryption'] === 'none' ? '' : $c['encryption'],
            'SMTPTimeout' => 20,
            'mailType'    => $html ? 'html' : 'text',
            'charset'     => 'UTF-8',
            'newline'     => "\r\n",
            'CRLF'        => "\r\n",
        ]);
        $email->setFrom($c['fromEmail'], $c['fromName']);
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($html ? $body : $body);

        if ($email->send(false)) {
            return ['ok' => true];
        }
        // printDebugger returns provider errors; trim to something presentable.
        $debug = strip_tags((string) $email->printDebugger(['headers']));
        return ['ok' => false, 'error' => trim(mb_substr($debug, 0, 300)) ?: 'The SMTP server rejected the message.'];
    }
}
