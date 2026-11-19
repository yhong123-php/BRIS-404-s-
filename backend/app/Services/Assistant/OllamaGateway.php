<?php

namespace App\Services\Assistant;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * AI model gateway (SDD §4.22): the only component that talks to the AI
 * language model service. The selected service is a locally hosted model
 * served by Ollama, so no resident data leaves the barangay's server
 * (SPMP §5.5.4). It applies a timeout so an unresponsive model never blocks
 * the rest of BRIS (SRS §3.3.3).
 */
class OllamaGateway
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeoutSeconds,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            rtrim(config('services.ollama.url'), '/'),
            config('services.ollama.model'),
            (int) config('services.ollama.timeout'),
        );
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * One non-streaming chat turn. Returns the assistant message:
     * ['content' => string, 'tool_calls' => [['name' => ..., 'arguments' => [...]], ...]].
     *
     * @throws AiServiceUnavailable
     */
    public function chat(array $messages, array $tools = [], ?array $jsonSchema = null): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => false,
            // Keep the model loaded (start-bris.bat preloads it) so no question pays the load time.
            'keep_alive' => -1,
            // Deterministic output: the assistant interprets and words, it does not improvise.
            'options' => ['temperature' => 0, 'num_ctx' => 8192],
        ];
        if ($tools !== []) {
            $payload['tools'] = $tools;
        }
        if ($jsonSchema !== null) {
            // Ollama structured outputs: the reply is constrained to this JSON schema.
            $payload['format'] = $jsonSchema;
        }

        try {
            // One automatic retry: Ollama can refuse the first connection or fail
            // the first load right after the computer starts.
            $response = Http::timeout($this->timeoutSeconds)
                ->connectTimeout(5)
                ->retry(2, 3000, fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError()), throw: false)
                ->acceptJson()
                ->post("{$this->baseUrl}/api/chat", $payload)
                ->throw();
        } catch (ConnectionException|RequestException $e) {
            throw new AiServiceUnavailable($e->getMessage(), previous: $e);
        }

        $message = $response->json('message') ?? [];

        $toolCalls = collect($message['tool_calls'] ?? [])->map(function ($call) {
            $args = $call['function']['arguments'] ?? [];
            if (is_string($args)) {
                $args = json_decode($args, true) ?: [];
            }

            return ['name' => (string) ($call['function']['name'] ?? ''), 'arguments' => is_array($args) ? $args : []];
        })->all();

        return ['content' => trim((string) ($message['content'] ?? '')), 'tool_calls' => $toolCalls];
    }

    public function isAvailable(): bool
    {
        try {
            $tags = Http::timeout(3)->get("{$this->baseUrl}/api/tags")->json('models') ?? [];

            return collect($tags)->contains(fn ($m) => ($m['name'] ?? '') === $this->model
                || ($m['name'] ?? '') === "{$this->model}:latest"
                || ($m['model'] ?? '') === $this->model);
        } catch (\Throwable) {
            return false;
        }
    }
}
