<?php

namespace App\Controllers\Api;

use App\Libraries\Razorpay;
use CodeIgniter\RESTful\ResourceController;
use Config\Database;
use Throwable;

/**
 * Subscription payments via Razorpay Checkout (one order per billing cycle).
 *
 *   GET  /api/payments/config   -> { enabled, keyId, currency }  (no secret)
 *   POST /api/payments/order    -> create a Razorpay order + a "created" record
 *   POST /api/payments/verify   -> verify the checkout signature, mark "paid"
 *   GET  /api/payments          -> this workspace's billing history
 *
 * Records are stored per-tenant in the `payments` table. Auth required.
 */
class Payments extends ResourceController
{
    protected $format = 'json';

    /** GET /api/payments/config — public-safe Razorpay settings for the client. */
    public function config()
    {
        $c = Razorpay::config();

        return $this->respond([
            'enabled'  => Razorpay::ready(),
            'keyId'    => $c['keyId'],
            'currency' => $c['currency'],
        ]);
    }

    /** GET /api/payments — the workspace's payment history (newest first). */
    public function index()
    {
        $me = $this->me();
        if ($me === null) {
            return $this->failUnauthorized('Not authenticated.');
        }
        try {
            $rows = Database::connect()->table('subscription_payments')->orderBy('id', 'DESC')->get()->getResultArray();
        } catch (Throwable $e) {
            return $this->fail('Billing history is unavailable. The payments table may not be migrated yet.', 500);
        }

        return $this->respond(['payments' => array_map([$this, 'shape'], $rows)]);
    }

    /** POST /api/payments/order — { planId, planName, cycle, amount(major), email, name }. */
    public function order()
    {
        $me = $this->me();
        if ($me === null) {
            return $this->failUnauthorized('Not authenticated.');
        }
        if (! Razorpay::ready()) {
            return $this->fail('Online payments are not set up yet. Ask your admin to add Razorpay keys in Platform Settings.', 503);
        }

        $in     = $this->request->getJSON(true) ?? [];
        $planId = trim((string) ($in['planId'] ?? ''));
        $cycle  = ($in['cycle'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
        $major  = (float) ($in['amount'] ?? 0);
        if ($planId === '' || $major <= 0) {
            return $this->failValidationErrors('A plan and a positive amount are required.');
        }

        $cfg    = Razorpay::config();
        $amount = (int) round($major * 100); // to paise/cents
        $rcpt   = 'sub_' . $planId . '_' . $me . '_' . time();

        $res = Razorpay::createOrder($amount, $cfg['currency'], $rcpt);
        if (! $res['ok']) {
            return $this->fail($res['error'] ?? 'Could not create the order.', 502);
        }
        $order = $res['order'];

        try {
            $db  = Database::connect();
            $now = date('Y-m-d H:i:s');
            $db->table('subscription_payments')->insert([
                'user_id'           => $me,
                'plan_id'           => $planId,
                'plan_name'         => substr((string) ($in['planName'] ?? $planId), 0, 128),
                'billing_cycle'     => $cycle,
                'amount'            => $amount,
                'currency'          => $cfg['currency'],
                'status'            => 'created',
                'razorpay_order_id' => (string) $order['id'],
                'payer_email'       => substr((string) ($in['email'] ?? ''), 0, 191) ?: null,
                'payer_name'        => substr((string) ($in['name'] ?? ''), 0, 128) ?: null,
                'created_at'        => $now,
            ]);
        } catch (Throwable $e) {
            return $this->fail('Could not record the order. The payments table may not be migrated yet.', 500);
        }

        return $this->respondCreated([
            'orderId'  => (string) $order['id'],
            'amount'   => $amount,
            'currency' => $cfg['currency'],
            'keyId'    => $cfg['keyId'],
        ]);
    }

    /** POST /api/payments/verify — { razorpay_order_id, razorpay_payment_id, razorpay_signature }. */
    public function verify()
    {
        $me = $this->me();
        if ($me === null) {
            return $this->failUnauthorized('Not authenticated.');
        }
        $in        = $this->request->getJSON(true) ?? [];
        $orderId   = trim((string) ($in['razorpay_order_id'] ?? ''));
        $paymentId = trim((string) ($in['razorpay_payment_id'] ?? ''));
        $signature = trim((string) ($in['razorpay_signature'] ?? ''));

        if ($orderId === '' || $paymentId === '' || $signature === '') {
            return $this->failValidationErrors('order id, payment id and signature are required.');
        }

        $valid = Razorpay::verifySignature($orderId, $paymentId, $signature);

        try {
            $db  = Database::connect();
            $db->table('subscription_payments')->where('razorpay_order_id', $orderId)->update([
                'status'              => $valid ? 'paid' : 'failed',
                'razorpay_payment_id' => $paymentId,
                'razorpay_signature'  => substr($signature, 0, 128),
                'paid_at'             => $valid ? date('Y-m-d H:i:s') : null,
            ]);
            $row = $db->table('subscription_payments')->where('razorpay_order_id', $orderId)->get()->getRowArray();
        } catch (Throwable $e) {
            return $this->fail('Could not record the payment result.', 500);
        }

        if (! $valid) {
            return $this->fail('Payment verification failed. The signature did not match.', 400);
        }

        return $this->respond(['ok' => true, 'payment' => $row ? $this->shape($row) : null]);
    }

    private function shape(array $r): array
    {
        return [
            'id'        => (int) $r['id'],
            'planId'    => (string) $r['plan_id'],
            'planName'  => (string) ($r['plan_name'] ?? ''),
            'cycle'     => (string) $r['billing_cycle'],
            'amount'    => (int) $r['amount'],
            'currency'  => (string) $r['currency'],
            'status'    => (string) $r['status'],
            'orderId'   => (string) ($r['razorpay_order_id'] ?? ''),
            'paymentId' => (string) ($r['razorpay_payment_id'] ?? ''),
            'payerEmail' => (string) ($r['payer_email'] ?? ''),
            'paidAt'    => $r['paid_at'] ? date('c', strtotime((string) $r['paid_at'])) : null,
            'createdAt' => $r['created_at'] ? date('c', strtotime((string) $r['created_at'])) : null,
        ];
    }

    private function me(): ?int
    {
        $id = $this->request->jwtUserId ?? null;

        return $id !== null && (int) $id > 0 ? (int) $id : null;
    }
}
