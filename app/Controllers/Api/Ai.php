<?php

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;

/**
 * Server-side relay to an LLM provider for the in-app AI assistant ("Nexus AI").
 *
 * The browser never sees the API key: the frontend assistant drives the
 * tool-use loop (its tools read Leads / HRMS / Inventory data that live in the
 * client) and POSTs each turn here in the Anthropic Messages shape; this
 * controller forwards it to the configured provider and returns a response in
 * that same shape so the frontend loop is provider-agnostic.
 *
 * Providers:
 *   - "groq"      — free, fast (Llama). Set GROQ_API_KEY. OpenAI-compatible API;
 *                   this controller translates the request/response to/from the
 *                   Anthropic shape so the frontend never changes.
 *   - "anthropic" — Claude. Set ANTHROPIC_API_KEY.
 *
 * Selection: AI_PROVIDER env, else inferred from whichever key is present
 * (Groq preferred when both are set, since it is the free option).
 */
class Ai extends ResourceController
{
    protected $format = 'json';

    private const ANTHROPIC_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const ANTHROPIC_VERSION   = '2023-06-01';
    private const ANTHROPIC_MODEL     = 'claude-opus-4-8';

    private const GROQ_ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';
    private const GROQ_MODEL     = 'llama-3.3-70b-versatile';

    /** POST /api/ai/chat  { system?, messages, tools?, max_tokens?, thinking? } */
    public function chat()
    {
        $groqKey      = trim((string) (getenv('GROQ_API_KEY') ?: ''));
        $anthropicKey = trim((string) (getenv('ANTHROPIC_API_KEY') ?: ''));
        $provider     = strtolower(trim((string) (getenv('AI_PROVIDER') ?: '')));

        if ($provider === '') {
            $provider = $groqKey !== '' ? 'groq' : ($anthropicKey !== '' ? 'anthropic' : '');
        }

        if ($provider === '' || ($provider === 'groq' && $groqKey === '') || ($provider === 'anthropic' && $anthropicKey === '')) {
            return $this->fail(
                'AI is not configured. Set GROQ_API_KEY (free, get one at console.groq.com) or ANTHROPIC_API_KEY in the backend .env file.',
                503,
            );
        }

        $input = $this->request->getJSON(true);
        if (! is_array($input) || empty($input['messages']) || ! is_array($input['messages'])) {
            return $this->failValidationErrors('A non-empty "messages" array is required.');
        }

        $maxTokens = min(8192, max(256, (int) ($input['max_tokens'] ?? 4096)));

        return $provider === 'groq'
            ? $this->relayGroq($input, $groqKey, $maxTokens)
            : $this->relayAnthropic($input, $anthropicKey, $maxTokens);
    }

    // ------------------------------------------------------------------
    // Anthropic (Claude) — pass the payload straight through.
    // ------------------------------------------------------------------

    private function relayAnthropic(array $input, string $apiKey, int $maxTokens)
    {
        $payload = [
            'model'      => (string) (getenv('ANTHROPIC_MODEL') ?: self::ANTHROPIC_MODEL),
            'max_tokens' => $maxTokens,
            'messages'   => $input['messages'],
        ];
        if (! empty($input['system'])) {
            $payload['system'] = $input['system'];
        }
        if (! empty($input['tools']) && is_array($input['tools'])) {
            $payload['tools'] = $input['tools'];
        }
        if (! empty($input['thinking'])) {
            $payload['thinking'] = $input['thinking'];
        }

        [$status, $decoded, $err] = $this->httpPostJson(self::ANTHROPIC_ENDPOINT, [
            'content-type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . self::ANTHROPIC_VERSION,
        ], $payload);

        if ($err !== null) {
            return $this->fail($err, 502);
        }
        if ($status < 200 || $status >= 300) {
            $message = $decoded['error']['message'] ?? 'The AI service returned an error.';

            return $this->fail($message, $status >= 400 && $status < 600 ? $status : 502);
        }

        return $this->respond($decoded);
    }

    // ------------------------------------------------------------------
    // Groq (OpenAI-compatible) — translate to OpenAI, call, translate back.
    // ------------------------------------------------------------------

    private function relayGroq(array $input, string $apiKey, int $maxTokens)
    {
        $messages = $this->toOpenAiMessages($input['messages'], $input['system'] ?? null);

        $payload = [
            'model'      => (string) (getenv('GROQ_MODEL') ?: self::GROQ_MODEL),
            'max_tokens' => $maxTokens,
            'messages'   => $messages,
        ];
        if (! empty($input['tools']) && is_array($input['tools'])) {
            $payload['tools']       = $this->toOpenAiTools($input['tools']);
            $payload['tool_choice'] = 'auto';
        }

        [$status, $decoded, $err] = $this->httpPostJson(self::GROQ_ENDPOINT, [
            'content-type: application/json',
            'authorization: Bearer ' . $apiKey,
        ], $payload);

        if ($err !== null) {
            return $this->fail($err, 502);
        }
        if ($status < 200 || $status >= 300) {
            $message = $decoded['error']['message'] ?? 'The AI service returned an error.';

            return $this->fail($message, $status >= 400 && $status < 600 ? $status : 502);
        }

        return $this->respond($this->toAnthropicReply($decoded));
    }

    /** Convert Anthropic-shaped messages (+ system) into OpenAI chat messages. */
    private function toOpenAiMessages(array $messages, $system): array
    {
        $out = [];
        if (is_string($system) && $system !== '') {
            $out[] = ['role' => 'system', 'content' => $system];
        }

        foreach ($messages as $m) {
            $role    = $m['role'] ?? 'user';
            $content = $m['content'] ?? '';

            if (is_string($content)) {
                $out[] = ['role' => $role, 'content' => $content];
                continue;
            }
            if (! is_array($content)) {
                continue;
            }

            if ($role === 'assistant') {
                $text      = '';
                $toolCalls = [];
                foreach ($content as $block) {
                    $type = $block['type'] ?? '';
                    if ($type === 'text') {
                        $text .= $block['text'] ?? '';
                    } elseif ($type === 'tool_use') {
                        $args        = $block['input'] ?? [];
                        $toolCalls[] = [
                            'id'       => $block['id'] ?? '',
                            'type'     => 'function',
                            'function' => [
                                'name'      => $block['name'] ?? '',
                                'arguments' => empty($args) ? '{}' : json_encode($args),
                            ],
                        ];
                    }
                }
                $msg = ['role' => 'assistant', 'content' => $text !== '' ? $text : null];
                if ($toolCalls !== []) {
                    $msg['tool_calls'] = $toolCalls;
                }
                $out[] = $msg;
                continue;
            }

            // user role: tool_result blocks become separate "tool" messages;
            // text blocks become a plain user message.
            $userText = '';
            foreach ($content as $block) {
                $type = $block['type'] ?? '';
                if ($type === 'tool_result') {
                    $resultContent = $block['content'] ?? '';
                    if (is_array($resultContent)) {
                        // Anthropic allows an array of blocks; flatten text out.
                        $flat = '';
                        foreach ($resultContent as $rc) {
                            $flat .= is_array($rc) ? ($rc['text'] ?? '') : (string) $rc;
                        }
                        $resultContent = $flat;
                    }
                    $out[] = [
                        'role'         => 'tool',
                        'tool_call_id' => $block['tool_use_id'] ?? '',
                        'content'      => (string) $resultContent,
                    ];
                } elseif ($type === 'text') {
                    $userText .= $block['text'] ?? '';
                }
            }
            if ($userText !== '') {
                $out[] = ['role' => 'user', 'content' => $userText];
            }
        }

        return $out;
    }

    /** Convert Anthropic tool defs ({name, description, input_schema}) to OpenAI. */
    private function toOpenAiTools(array $tools): array
    {
        $out = [];
        foreach ($tools as $t) {
            $params = $t['input_schema'] ?? ['type' => 'object', 'properties' => new \stdClass()];
            $out[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $t['name'] ?? '',
                    'description' => $t['description'] ?? '',
                    'parameters'  => $this->fixJsonSchema($params),
                ],
            ];
        }

        return $out;
    }

    /**
     * json_decode turns an empty JSON object {} into an empty PHP array [],
     * which json_encode then re-emits as [] — but JSON Schema requires
     * "properties" to be an object. Coerce empty "properties" (and nested ones)
     * back to stdClass so they serialise as {}.
     */
    private function fixJsonSchema($schema)
    {
        if (! is_array($schema)) {
            return $schema;
        }
        if (array_key_exists('properties', $schema)) {
            if (empty($schema['properties'])) {
                $schema['properties'] = new \stdClass();
            } else {
                foreach ($schema['properties'] as $k => $v) {
                    $schema['properties'][$k] = $this->fixJsonSchema($v);
                }
            }
        }

        return $schema;
    }

    /** Convert an OpenAI chat completion back into the Anthropic Messages shape. */
    private function toAnthropicReply(array $decoded): array
    {
        $choice  = $decoded['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $finish  = $choice['finish_reason'] ?? 'stop';

        $blocks = [];
        if (! empty($message['content'])) {
            $blocks[] = ['type' => 'text', 'text' => (string) $message['content']];
        }
        foreach (($message['tool_calls'] ?? []) as $call) {
            $rawArgs = $call['function']['arguments'] ?? '{}';
            $parsed  = json_decode((string) $rawArgs, true);
            $blocks[] = [
                'type'  => 'tool_use',
                'id'    => $call['id'] ?? '',
                'name'  => $call['function']['name'] ?? '',
                'input' => is_array($parsed) ? $parsed : [],
            ];
        }
        if ($blocks === []) {
            $blocks[] = ['type' => 'text', 'text' => ''];
        }

        return [
            'id'          => $decoded['id'] ?? '',
            'type'        => 'message',
            'role'        => 'assistant',
            'content'     => $blocks,
            'stop_reason' => $finish === 'tool_calls' ? 'tool_use' : 'end_turn',
            'usage'       => $decoded['usage'] ?? null,
        ];
    }

    // ------------------------------------------------------------------

    /** POST JSON and return [httpStatus, decodedBody|null, errorString|null]. */
    private function httpPostJson(string $url, array $headers, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($payload),
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlEr = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return [0, null, 'Could not reach the AI service: ' . $curlEr];
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return [$status, null, 'Unexpected response from the AI service.'];
        }

        return [$status, $decoded, null];
    }
}
