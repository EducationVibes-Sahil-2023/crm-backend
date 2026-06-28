<?php

namespace App\Libraries;

use Config\Database;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Web Push helper — VAPID key management + payload delivery.
 *
 * VAPID keys identify this server to the browser push services (FCM, Mozilla,
 * WNS). The public key is shared with the browser at subscribe time; the
 * private key signs the push request and must stay server-side. Keys are read
 * from .env first (webpush.publicKey / webpush.privateKey / webpush.subject),
 * otherwise generated once and persisted in the `settings` table so they remain
 * stable across requests and deploys.
 *
 * Subscriptions live in the per-tenant `push_subscriptions` table. Sending a
 * payload encrypts it for each subscription (RFC 8291) and POSTs it to the push
 * service; expired endpoints (404/410) are pruned automatically.
 */
class Push
{
    private const SETTINGS_KEY = 'webpush_vapid';
    private const DEFAULT_SUBJECT = 'mailto:admin@educationvibes.in';

    /**
     * Resolve the VAPID keypair + subject, generating + persisting on first use.
     *
     * @return array{publicKey:string,privateKey:string,subject:string}|null
     *         null when keys can't be obtained (e.g. DB unavailable and no .env).
     */
    public static function vapid(): ?array
    {
        // 1. Environment override (preferred for production secrets).
        $pub  = (string) (env('webpush.publicKey') ?: '');
        $priv = (string) (env('webpush.privateKey') ?: '');
        $subj = (string) (env('webpush.subject') ?: '');
        if ($pub !== '' && $priv !== '') {
            return [
                'publicKey'  => $pub,
                'privateKey' => $priv,
                'subject'    => $subj !== '' ? $subj : self::DEFAULT_SUBJECT,
            ];
        }

        // 2. Persisted in the settings table.
        $stored = Settings::get(self::SETTINGS_KEY);
        if (is_array($stored) && ! empty($stored['publicKey']) && ! empty($stored['privateKey'])) {
            return [
                'publicKey'  => (string) $stored['publicKey'],
                'privateKey' => (string) $stored['privateKey'],
                'subject'    => (string) ($stored['subject'] ?? self::DEFAULT_SUBJECT),
            ];
        }

        // 3. Generate a fresh pair and persist it (best-effort).
        try {
            $keys = VAPID::createVapidKeys(); // ['publicKey' => ..., 'privateKey' => ...]
        } catch (Throwable $e) {
            return null;
        }
        $value = [
            'publicKey'  => (string) $keys['publicKey'],
            'privateKey' => (string) $keys['privateKey'],
            'subject'    => self::DEFAULT_SUBJECT,
        ];
        try {
            Settings::set(self::SETTINGS_KEY, $value);
        } catch (Throwable $e) {
            // Can't persist (no settings table) — still usable for this request.
        }
        return $value;
    }

    /** The base64url VAPID public key the browser needs to subscribe, or '' if unavailable. */
    public static function publicKey(): string
    {
        $v = self::vapid();
        return $v['publicKey'] ?? '';
    }

    /**
     * Send a payload to every subscription belonging to a user.
     *
     * @param array<string,mixed> $payload Notification shape: {title, body, icon, url, tag, ...}
     * @return array{sent:int,failed:int,pruned:int}
     */
    public static function sendToUser(int $userId, array $payload): array
    {
        return self::dispatch(['user_id' => $userId], $payload);
    }

    /**
     * Broadcast a payload to every subscription in the (tenant) database.
     *
     * @param array<string,mixed> $payload
     * @return array{sent:int,failed:int,pruned:int}
     */
    public static function broadcast(array $payload): array
    {
        return self::dispatch([], $payload);
    }

    /**
     * @param array<string,mixed> $where   Filter on push_subscriptions (e.g. ['user_id' => 5]).
     * @param array<string,mixed> $payload
     * @return array{sent:int,failed:int,pruned:int}
     */
    private static function dispatch(array $where, array $payload): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'pruned' => 0];

        $vapid = self::vapid();
        if ($vapid === null) {
            return $result;
        }

        try {
            $builder = Database::connect()->table('push_subscriptions');
            if ($where !== []) {
                $builder->where($where);
            }
            $rows = $builder->get()->getResultArray();
        } catch (Throwable $e) {
            return $result; // table missing / DB down
        }
        if ($rows === []) {
            return $result;
        }

        try {
            $webPush = new WebPush(['VAPID' => $vapid]);
        } catch (Throwable $e) {
            return $result;
        }

        $body       = json_encode($payload);
        $byEndpoint = [];
        foreach ($rows as $r) {
            $endpoint = (string) ($r['endpoint'] ?? '');
            if ($endpoint === '') {
                continue;
            }
            $byEndpoint[$endpoint] = (int) ($r['id'] ?? 0);
            try {
                $sub = Subscription::create([
                    'endpoint'        => $endpoint,
                    'keys'            => [
                        'p256dh' => (string) ($r['p256dh'] ?? ''),
                        'auth'   => (string) ($r['auth'] ?? ''),
                    ],
                    'contentEncoding' => 'aes128gcm',
                ]);
                $webPush->queueNotification($sub, $body);
            } catch (Throwable $e) {
                $result['failed']++;
            }
        }

        $stale = [];
        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $result['sent']++;
                continue;
            }
            $result['failed']++;
            // 404/410 => the subscription is gone; remove it.
            if ($report->isSubscriptionExpired()) {
                $stale[] = $report->getEndpoint();
            }
        }

        if ($stale !== []) {
            try {
                Database::connect()->table('push_subscriptions')->whereIn('endpoint', $stale)->delete();
                $result['pruned'] = count($stale);
            } catch (Throwable $e) {
                // ignore prune failure
            }
        }

        return $result;
    }
}
