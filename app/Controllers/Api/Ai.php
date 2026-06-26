<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;

/**
 * Server-side relay to the Anthropic (Claude) Messages API.
 *
 * The browser never sees the API key: the frontend AI assistant drives the
 * tool-use loop (its tools read Leads / HRMS / Inventory data that live in the
 * client) and POSTs each turn here; this controller forwards the request to
 * Anthropic with the secret key attached and returns Claude's raw response so
 * the frontend can continue the loop.
 */
class Ai extends ResourceController
{
    protected $format = 'json';

    private const ENDPOINT   = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const DEFAULT_MODEL = 'claude-opus-4-8';

    /** POST /api/ai/chat  { system?, messages, tools?, max_tokens? } */
    public function chat()
    {
        $apiKey = trim((string) (getenv('ANTHROPIC_API_KEY') ?: ''));
        if ($apiKey === '') {
            return $this->fail(
                'AI is not configured. Set ANTHROPIC_API_KEY in the backend .env file.',
                503,
            );
        }

        $input = $this->request->getJSON(true);
        if (! is_array($input) || empty($input['messages']) || ! is_array($input['messages'])) {
            return $this->failValidationErrors('A non-empty "messages" array is required.');
        }

        $payload = [
            'model'      => (string) (getenv('ANTHROPIC_MODEL') ?: self::DEFAULT_MODEL),
            'max_tokens' => min(8192, max(256, (int) ($input['max_tokens'] ?? 4096))),
            'messages'   => $input['messages'],
        ];
        if (! empty($input['system'])) {
            $payload['system'] = $input['system'];
        }
        if (! empty($input['tools']) && is_array($input['tools'])) {
            $payload['tools'] = $input['tools'];
        }
        // Adaptive thinking improves multi-step tool reasoning. Thinking blocks
        // are echoed back by the client on the next turn, as the API requires.
        if (! empty($input['thinking'])) {
            $payload['thinking'] = $input['thinking'];
        }

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return $this->fail('Could not reach the AI service: ' . $err, 502);
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return $this->fail('Unexpected response from the AI service.', 502);
        }

        if ($status < 200 || $status >= 300) {
            $message = $decoded['error']['message'] ?? 'The AI service returned an error.';

            return $this->fail($message, $status >= 400 && $status < 600 ? $status : 502);
        }

        return $this->respond($decoded);
    }
}
