<?php

namespace App\Controllers\Api;

use App\Libraries\SmtpService;
use CodeIgniter\RESTful\ResourceController;

/**
 * SMTP relay configuration + sending. All endpoints behind the JWT `auth`
 * filter. The relay config is global (one platform mail server), so the
 * password is never echoed back — only a `hasPassword` flag.
 */
class Smtp extends ResourceController
{
    protected $format = 'json';

    private function smtp(): SmtpService
    {
        return new SmtpService();
    }

    /** GET /api/smtp/config */
    public function getConfig()
    {
        return $this->respond($this->smtp()->publicConfig());
    }

    /** POST /api/smtp/config { host, port, username, password?, encryption, fromName, fromEmail } */
    public function saveConfig()
    {
        $in = $this->request->getJSON(true) ?: [];
        $svc = $this->smtp();
        $svc->saveConfig($in);
        return $this->respond($svc->publicConfig());
    }

    /** POST /api/smtp/test { to } — send a fixed test email. */
    public function test()
    {
        $in = $this->request->getJSON(true) ?: [];
        $to = trim((string) ($in['to'] ?? ''));
        if ($to === '') {
            return $this->failValidationErrors('A recipient ("to") is required.');
        }
        $res = $this->smtp()->send(
            $to,
            'SMTP test from your CRM',
            "This is a test email confirming your SMTP relay is working.\r\n\r\nIf you received this, sending is configured correctly.",
        );
        return $res['ok']
            ? $this->respond(['sent' => true])
            : $this->fail($res['error'] ?? 'Send failed.', 502);
    }

    /** POST /api/smtp/send { to, subject, body, html? } */
    public function send()
    {
        $in   = $this->request->getJSON(true) ?: [];
        $to   = trim((string) ($in['to'] ?? ''));
        $res  = $this->smtp()->send(
            $to,
            (string) ($in['subject'] ?? ''),
            (string) ($in['body'] ?? ''),
            (bool) ($in['html'] ?? false),
        );
        return $res['ok']
            ? $this->respond(['sent' => true])
            : $this->fail($res['error'] ?? 'Send failed.', 502);
    }
}
