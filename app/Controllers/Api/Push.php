<?php

namespace App\Controllers\Api;

use App\Libraries\Push as PushLib;
use CodeIgniter\RESTful\ResourceController;
use Config\Database;
use Throwable;

/**
 * Web Push API.
 *
 *   GET  /api/push/vapid        (public)  -> { enabled, publicKey }
 *   POST /api/push/subscribe    (auth)    -> store a PushSubscription
 *   POST /api/push/unsubscribe  (auth)    -> remove a PushSubscription
 *   POST /api/push/test         (auth)    -> send a test notification to me
 *
 * The browser fetches the public key, calls pushManager.subscribe() with it,
 * and POSTs the resulting subscription here. The private key never leaves the
 * server (see App\Libraries\Push).
 */
class Push extends ResourceController
{
    protected $format = 'json';

    /** GET /api/push/vapid — public; the client needs this before subscribing. */
    public function vapid()
    {
        $publicKey = PushLib::publicKey();

        return $this->respond([
            'enabled'   => $publicKey !== '',
            'publicKey' => $publicKey,
        ]);
    }

    /** POST /api/push/subscribe — upsert the browser's subscription by endpoint. */
    public function subscribe()
    {
        $sub      = $this->subscriptionFromBody();
        if ($sub === null) {
            return $this->failValidationErrors('A valid push subscription (endpoint + keys) is required.');
        }

        $userId = $this->currentUserId();
        $now    = date('Y-m-d H:i:s');
        $data   = [
            'user_id'    => $userId,
            'p256dh'     => $sub['p256dh'],
            'auth'       => $sub['auth'],
            'user_agent' => substr((string) $this->request->getUserAgent(), 0, 255),
            'updated_at' => $now,
        ];

        try {
            $db = Database::connect();
            if ($db->table('push_subscriptions')->where('endpoint', $sub['endpoint'])->countAllResults() > 0) {
                $db->table('push_subscriptions')->where('endpoint', $sub['endpoint'])->update($data);
            } else {
                $db->table('push_subscriptions')->insert($data + ['endpoint' => $sub['endpoint'], 'created_at' => $now]);
            }
        } catch (Throwable $e) {
            return $this->fail('Could not store the subscription. The push tables may not be migrated yet.', 500);
        }

        return $this->respond(['subscribed' => true]);
    }

    /** POST /api/push/unsubscribe — remove a subscription by endpoint. */
    public function unsubscribe()
    {
        $body     = $this->request->getJSON(true) ?: [];
        $endpoint = (string) ($body['endpoint'] ?? '');
        if ($endpoint === '') {
            return $this->failValidationErrors('An endpoint is required.');
        }
        try {
            Database::connect()->table('push_subscriptions')->where('endpoint', $endpoint)->delete();
        } catch (Throwable $e) {
            // table missing — nothing to remove
        }

        return $this->respond(['unsubscribed' => true]);
    }

    /** POST /api/push/test — send a test notification to the caller's devices. */
    public function test()
    {
        $userId = $this->currentUserId();
        $body   = $this->request->getJSON(true) ?: [];

        $payload = [
            'title' => (string) ($body['title'] ?? 'Nexus CRM & HRMS'),
            'body'  => (string) ($body['body'] ?? 'This is a test push notification.'),
            'icon'  => (string) ($body['icon'] ?? '/icon-192.png'),
            'url'   => (string) ($body['url'] ?? '/dashboard'),
            'tag'   => 'nexus-test',
        ];

        // Test pushes are scoped to the signed-in user's own subscriptions.
        $report = $userId !== null
            ? PushLib::sendToUser($userId, $payload)
            : ['sent' => 0, 'failed' => 0, 'pruned' => 0];

        if ($report['sent'] === 0 && $report['failed'] === 0) {
            return $this->respond([
                'ok'      => false,
                'report'  => $report,
                'message' => 'No active subscriptions for this user, or push is not configured.',
            ]);
        }

        return $this->respond(['ok' => $report['sent'] > 0, 'report' => $report]);
    }

    /** POST /api/push/device — register a native (FCM/APNs) device token. */
    public function device()
    {
        $body  = $this->request->getJSON(true) ?: [];
        $token = (string) ($body['token'] ?? '');
        if ($token === '') {
            return $this->failValidationErrors('A device token is required.');
        }
        $platform = (string) ($body['platform'] ?? '');
        $platform = in_array($platform, ['ios', 'android'], true) ? $platform : null;

        $now  = date('Y-m-d H:i:s');
        $data = [
            'user_id'    => $this->currentUserId(),
            'platform'   => $platform,
            'updated_at' => $now,
        ];
        try {
            $db = Database::connect();
            if ($db->table('device_tokens')->where('token', $token)->countAllResults() > 0) {
                $db->table('device_tokens')->where('token', $token)->update($data);
            } else {
                $db->table('device_tokens')->insert($data + ['token' => $token, 'created_at' => $now]);
            }
        } catch (Throwable $e) {
            return $this->fail('Could not store the device token. The push tables may not be migrated yet.', 500);
        }

        return $this->respond(['registered' => true]);
    }

    /** POST /api/push/device/remove — unregister a native device token. */
    public function deviceRemove()
    {
        $body  = $this->request->getJSON(true) ?: [];
        $token = (string) ($body['token'] ?? '');
        if ($token === '') {
            return $this->failValidationErrors('A device token is required.');
        }
        try {
            Database::connect()->table('device_tokens')->where('token', $token)->delete();
        } catch (Throwable $e) {
            // table missing — nothing to remove
        }

        return $this->respond(['removed' => true]);
    }

    /**
     * Normalise the PushSubscription from the request body. Accepts either the
     * browser's `subscription.toJSON()` shape ({endpoint, keys:{p256dh, auth}})
     * or a flat {endpoint, p256dh, auth}.
     *
     * @return array{endpoint:string,p256dh:string,auth:string}|null
     */
    private function subscriptionFromBody(): ?array
    {
        $body = $this->request->getJSON(true) ?: [];
        // Allow the subscription to be nested under a `subscription` key.
        if (isset($body['subscription']) && is_array($body['subscription'])) {
            $body = $body['subscription'];
        }
        $endpoint = (string) ($body['endpoint'] ?? '');
        $p256dh   = (string) ($body['keys']['p256dh'] ?? $body['p256dh'] ?? '');
        $auth     = (string) ($body['keys']['auth'] ?? $body['auth'] ?? '');
        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            return null;
        }

        return ['endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth];
    }

    /** The authenticated user id set by the JwtAuth filter, if any. */
    private function currentUserId(): ?int
    {
        $id = $this->request->jwtUserId ?? null;

        return $id !== null ? (int) $id : null;
    }
}
