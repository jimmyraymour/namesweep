<?php

declare(strict_types=1);

namespace NameSweep\Suggest;

use NameSweep\Util\HttpClient;
use NameSweep\Util\Logger;

/**
 * LLM client with two backends: Ollama (primary) and OpenRouter (fallback).
 *
 * Backend selection per call:
 *   - Default alias is the primary (ollama:<model>). The fallback backend is
 *     used when the primary errors, returns an empty body, or returns the
 *     same text 3 times in a row (the model is stuck).
 *   - If an explicit alias is given (`ollama:<model>` / `openrouter:<id>`),
 *     only that backend is tried and it may throw.
 *
 * The backend actually used is returned with the text so callers can persist
 * it (e.g. suggest_sessions.model).
 */
final class LlmClient
{
    /** How many identical consecutive outputs count as "model stuck". */
    private const STUCK_WINDOW = 3;

    public function __construct(
        private readonly HttpClient $http,
        private readonly array $config,
        private readonly Logger $logger
    ) {
    }

    /**
     * Runs a chat completion and returns the assistant text.
     *
     * @return array{model:string, text:string}
     * @throws \RuntimeException when every backend failed or returned nothing
     */
    public function generate(string $systemPrompt, string $userPrompt, float $temperature = 0.7, ?string $modelAlias = null): array
    {
        if ($modelAlias !== null) {
            return ['model' => $modelAlias, 'text' => $this->generateWith($modelAlias, $systemPrompt, $userPrompt, $temperature)];
        }

        $backends = $this->backends();
        $outputs  = [];

        foreach ($backends as $alias) {
            try {
                $text = $this->generateWith($alias, $systemPrompt, $userPrompt, $temperature);
            } catch (\Throwable $e) {
                $this->logger->warning("LLM backend {$alias} failed", ['error' => $e->getMessage()], 'llm');
                continue;
            }

            $text = trim($text);
            if ($text === '') {
                $this->logger->warning("LLM backend {$alias} returned empty output", [], 'llm');
                continue;
            }

            // Stuck model guard: 3 identical outputs in a row → treat as a miss.
            $outputs[] = $text;
            if (count($outputs) >= self::STUCK_WINDOW) {
                $recent = array_slice($outputs, -self::STUCK_WINDOW);
                if (count(array_unique($recent)) === 1) {
                    $this->logger->warning("LLM backend {$alias} returned identical output " . self::STUCK_WINDOW . 'x — switching backend', [], 'llm');
                    continue;
                }
            }

            return ['model' => $alias, 'text' => $text];
        }

        throw new \RuntimeException('All LLM backends failed or returned empty output');
    }

    /**
     * Same as generate(), but the output must parse as a JSON array (or an
     * object that carries an array under $key). Retries once with the same
     * prompt, then moves to the next backend (if any), then throws.
     *
     * @return array{model:string, data:array}
     * @throws \RuntimeException on unparseable output from every backend
     */
    public function generateJson(string $systemPrompt, string $userPrompt, float $temperature = 0.7, ?string $key = null, ?string $modelAlias = null): array
    {
        if ($modelAlias !== null) {
            return ['model' => $modelAlias, 'data' => $this->jsonFrom($modelAlias, $systemPrompt, $userPrompt, $temperature, $key)];
        }

        $backends = $this->backends();
        foreach ($backends as $alias) {
            try {
                return ['model' => $alias, 'data' => $this->jsonFrom($alias, $systemPrompt, $userPrompt, $temperature, $key)];
            } catch (\Throwable $e) {
                $this->logger->warning("LLM backend {$alias} produced no parseable JSON", ['error' => $e->getMessage()], 'llm');
            }
        }

        throw new \RuntimeException('All LLM backends failed to produce parseable JSON');
    }

    /**
     * @return string[] backend aliases in priority order (primary first)
     */
    private function backends(): array
    {
        $aliases = ['ollama:' . ($this->config['ollama_model'] ?? 'llama3.1:8b')];
        if (self::centralKey((string) ($this->config['openrouter_api_key'] ?? '')) !== '') {
            $aliases[] = 'openrouter:' . ($this->config['openrouter_model'] ?? 'meta-llama/llama-3.1-8b-instruct:free');
        }
        return $aliases;
    }

    private function jsonFrom(string $alias, string $system, string $user, float $temperature, ?string $key): array
    {
        $last = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $text = $this->generateWith($alias, $system, $user, $temperature);
            } catch (\Throwable $e) {
                $last = $e;
                break; // backend is down — the caller moves to the next one
            }

            $data = $this->parseJson($text, $key);
            if ($data !== null) {
                return $data;
            }
            $last = new \RuntimeException('Model output is not valid JSON');
            $this->logger->warning("LLM JSON parse failed (attempt {$attempt}) on {$alias}", ['sample' => mb_substr($text, 0, 200)], 'llm');
        }

        throw $last ?? new \RuntimeException("No parseable JSON from {$alias}");
    }

    private function parseJson(string $text, ?string $key): ?array
    {
        // Models often wrap the JSON in ```json fences — strip them.
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        }

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            return null;
        }

        $payload = $decoded;
        if ($key !== null) {
            $payload = $decoded[$key] ?? null;
        }
        return is_array($payload) ? $payload : null;
    }

    private function generateWith(string $alias, string $system, string $user, float $temperature): string
    {
        if (str_starts_with($alias, 'ollama:')) {
            return $this->ollama(substr($alias, strlen('ollama:')), $system, $user, $temperature);
        }
        if (str_starts_with($alias, 'openrouter:')) {
            return $this->openRouter(substr($alias, strlen('openrouter:')), $system, $user, $temperature);
        }
        throw new \RuntimeException("Unknown LLM alias: {$alias}");
    }

    private function ollama(string $model, string $system, string $user, float $temperature): string
    {
        $base = rtrim((string) ($this->config['ollama_base_url'] ?? 'http://127.0.0.1:11434'), '/');
        $body = json_encode([
            'model'    => $model,
            'stream'   => false,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'options'  => ['temperature' => $temperature],
        ], JSON_THROW_ON_ERROR);

        $response = $this->http->postJson($base . '/api/chat', $body, 180);
        if ($response['status'] !== 200) {
            $err = json_decode($response['body'], true)['error'] ?? 'no detail';
            throw new \RuntimeException("Ollama HTTP {$response['status']}: {$err}");
        }

        $data = json_decode($response['body'], true);
        $text = is_array($data) ? ($data['message']['content'] ?? null) : null;
        if (!is_string($text)) {
            throw new \RuntimeException('Ollama returned no message content');
        }
        return $text;
    }

    private function openRouter(string $model, string $system, string $user, float $temperature): string
    {
        // Phase 5: the key lives centrally in Bifrost, scoped to this project.
        // Resolved here rather than in config/app.php so the CLI does not pay an
        // HTTP round trip on commands that never reach the LLM. The local env
        // value stays as the fallback until the cutover is confirmed.
        $apiKey = self::centralKey((string) ($this->config['openrouter_api_key'] ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException('OpenRouter API key is not configured');
        }

        $body = json_encode([
            'model'       => $model,
            'temperature' => $temperature,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
        ], JSON_THROW_ON_ERROR);

        $response = $this->http->postJson(
            'https://openrouter.ai/api/v1/chat/completions',
            $body,
            60,
            ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json']
        );
        if ($response['status'] !== 200) {
            $err = json_decode($response['body'], true)['error']['message'] ?? $response['body'];
            throw new \RuntimeException("OpenRouter HTTP {$response['status']}: {$err}");
        }

        $data = json_decode($response['body'], true);
        $text = is_array($data) ? ($data['choices'][0]['message']['content'] ?? null) : null;
        if (!is_string($text)) {
            throw new \RuntimeException('OpenRouter returned no message content');
        }
        return $text;
    }

    /**
     * The OpenRouter key: Bifrost first, the local value as the migration
     * fallback. Memoized per request by the client, so repeat calls are free.
     */
    private static function centralKey(string $local): string
    {
        if (!class_exists('Bifrost', false)) {
            return $local;
        }
        try {
            return \Bifrost::secret('openrouter', 'OPENROUTER_API_KEY');
        } catch (\Throwable) {
            return $local;
        }
    }
}