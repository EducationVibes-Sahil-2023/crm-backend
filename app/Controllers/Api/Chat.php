<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;
use Config\Database;
use Throwable;

/**
 * Real 1:1 chat between login accounts in the same workspace.
 *
 *   GET  /api/chat/overview          -> per-contact last message + unread count
 *   GET  /api/chat/messages?with=ID  -> the thread with one user (marks it read)
 *   POST /api/chat/messages          -> { to, body } send a message
 *
 * Tenant-scoped: the JwtAuth filter points the DB at the caller's database, so a
 * client and their users only chat with each other. The platform super-admin is
 * not a `users` row and never appears here. Auth required (see Filters).
 */
class Chat extends ResourceController
{
    protected $format = 'json';

    /** GET /api/chat/overview — one row per person I've exchanged messages with. */
    public function overview()
    {
        $me = $this->me();
        if ($me === null) {
            return $this->failUnauthorized('Not authenticated.');
        }

        try {
            $db   = Database::connect();
            $rows = $db->table('chat_messages')
                ->where('sender_id', $me)
                ->orWhere('recipient_id', $me)
                ->orderBy('id', 'DESC')
                ->get()->getResultArray();
        } catch (Throwable $e) {
            return $this->fail('Chat is not available. The chat table may not be migrated yet.', 500);
        }

        // Reduce to the latest message per "other" user + count unread (to me).
        $out = [];
        foreach ($rows as $r) {
            $other = (int) $r['sender_id'] === $me ? (int) $r['recipient_id'] : (int) $r['sender_id'];
            if (! isset($out[$other])) {
                $out[$other] = [
                    'userId' => $other,
                    'body'   => (string) $r['body'],
                    'at'     => (string) $r['created_at'],
                    'mine'   => (int) $r['sender_id'] === $me,
                    'unread' => 0,
                ];
            }
            if ((int) $r['recipient_id'] === $me && $r['read_at'] === null) {
                $out[$other]['unread']++;
            }
        }

        return $this->respond(['threads' => array_values($out)]);
    }

    /** GET /api/chat/messages?with=ID — full thread with one user; marks it read. */
    public function messages()
    {
        $me = $this->me();
        if ($me === null) {
            return $this->failUnauthorized('Not authenticated.');
        }
        $other = (int) $this->request->getGet('with');
        if ($other <= 0) {
            return $this->failValidationErrors('A "with" user id is required.');
        }

        try {
            $db   = Database::connect();
            $rows = $db->table('chat_messages')
                ->groupStart()
                    ->where('sender_id', $me)->where('recipient_id', $other)
                ->groupEnd()
                ->orGroupStart()
                    ->where('sender_id', $other)->where('recipient_id', $me)
                ->groupEnd()
                ->orderBy('id', 'ASC')
                ->get()->getResultArray();

            // Mark their messages to me as read.
            $db->table('chat_messages')
                ->where('sender_id', $other)
                ->where('recipient_id', $me)
                ->where('read_at', null)
                ->update(['read_at' => date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            return $this->fail('Chat is not available. The chat table may not be migrated yet.', 500);
        }

        $messages = array_map(static fn ($r) => [
            'id'        => (int) $r['id'],
            'senderId'  => (int) $r['sender_id'],
            'recipientId' => (int) $r['recipient_id'],
            'body'      => (string) $r['body'],
            'createdAt' => (string) $r['created_at'],
            'read'      => $r['read_at'] !== null,
        ], $rows);

        return $this->respond(['messages' => $messages]);
    }

    /** POST /api/chat/messages — { to, body }. */
    public function send()
    {
        $me = $this->me();
        if ($me === null) {
            return $this->failUnauthorized('Not authenticated.');
        }
        $in   = $this->request->getJSON(true) ?? [];
        $to   = (int) ($in['to'] ?? 0);
        $body = trim((string) ($in['body'] ?? ''));
        if ($to <= 0 || $body === '') {
            return $this->failValidationErrors('A recipient ("to") and message "body" are required.');
        }
        if ($to === $me) {
            return $this->failValidationErrors('You cannot message yourself.');
        }

        // Recipient must be a real account in THIS workspace.
        try {
            $db = Database::connect();
            if ($db->table('users')->where('id', $to)->countAllResults() === 0) {
                return $this->failValidationErrors('That user is not in your workspace.');
            }
            $now = date('Y-m-d H:i:s');
            $db->table('chat_messages')->insert([
                'sender_id'    => $me,
                'recipient_id' => $to,
                'body'         => $body,
                'read_at'      => null,
                'created_at'   => $now,
            ]);
            $id = (int) $db->insertID();
        } catch (Throwable $e) {
            return $this->fail('Could not send the message. The chat table may not be migrated yet.', 500);
        }

        return $this->respondCreated([
            'message' => [
                'id'          => $id,
                'senderId'    => $me,
                'recipientId' => $to,
                'body'        => $body,
                'createdAt'   => $now,
                'read'        => false,
            ],
        ]);
    }

    /** The authenticated user id from the JwtAuth filter. */
    private function me(): ?int
    {
        $id = $this->request->jwtUserId ?? null;

        return $id !== null && (int) $id > 0 ? (int) $id : null;
    }
}
