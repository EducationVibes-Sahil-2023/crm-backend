<?php

namespace App\Libraries;

/**
 * Thin Razorpay helper. Reads the platform owner's keys from the saved platform
 * config (Super Admin → Platform Settings → Razorpay), creates Orders via the
 * REST API, and verifies the checkout signature. No SDK required.
 *
 * Keys live in settings['platform.config'].payment — { enabled, keyId, keySecret,
 * currency }. The platform owner collects subscription payments from clients, so
 * the keys are platform-level (the main DB's settings).
 */
class Razorpay
{
    private const API = 'https://api.razorpay.com/v1';

    /** @return array{enabled:bool,keyId:string,keySecret:string,currency:string} */
    public static function config(): array
    {
        $cfg = Settings::get('platform.config') ?? [];
        $pay = is_array($cfg['payment'] ?? null) ? $cfg['payment'] : [];

        return [
            'enabled'   => (bool) ($pay['enabled'] ?? false),
            'keyId'     => trim((string) ($pay['keyId'] ?? '')),
            'keySecret' => trim((string) ($pay['keySecret'] ?? '')),
            'currency'  => strtoupper(trim((string) ($pay['currency'] ?? 'INR'))) ?: 'INR',
        ];
    }

    /** True when Razorpay is enabled and both keys are present. */
    public static function ready(): bool
    {
        $c = self::config();

        return $c['enabled'] && $c['keyId'] !== '' && $c['keySecret'] !== '';
    }

    /**
     * Create an order. $amount is in the smallest currency unit (paise/cents).
     *
     * @return array{ok:bool,order?:array<string,mixed>,error?:string}
     */
    public static function createOrder(int $amount, string $currency, string $receipt): array
    {
        $c = self::config();
        if ($c['keyId'] === '' || $c['keySecret'] === '') {
            return ['ok' => false, 'error' => 'Razorpay keys are not configured.'];
        }
        if ($amount <= 0) {
            return ['ok' => false, 'error' => 'A positive amount is required.'];
        }

        $payload = [
            'amount'          => $amount,
            'currency'        => $currency ?: $c['currency'],
            'receipt'         => substr($receipt, 0, 40),
            'payment_capture' => 1,
        ];

        $ch = curl_init(self::API . '/orders');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => $c['keyId'] . ':' . $c['keySecret'],
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 20,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'error' => 'Could not reach Razorpay: ' . $err];
        }
        $data = json_decode((string) $body, true);
        if ($code >= 200 && $code < 300 && isset($data['id'])) {
            return ['ok' => true, 'order' => $data];
        }
        $msg = $data['error']['description'] ?? "Razorpay order failed (HTTP {$code}).";

        return ['ok' => false, 'error' => (string) $msg];
    }

    /** Verify the checkout signature: HMAC-SHA256(order_id|payment_id, keySecret). */
    public static function verifySignature(string $orderId, string $paymentId, string $signature): bool
    {
        $c = self::config();
        if ($c['keySecret'] === '' || $orderId === '' || $paymentId === '' || $signature === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $c['keySecret']);

        return hash_equals($expected, $signature);
    }
}
