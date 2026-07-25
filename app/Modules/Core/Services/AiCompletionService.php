<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\AiUsageLog;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class AiCompletionService
{
    /**
     * Call an OpenAI-compatible chat completions endpoint.
     *
     * @param  array{temperature?: float, timeout?: int, max_tokens?: int, operation?: string}  $options
     */
    public function complete(string $systemPrompt, string $userPrompt, array $options = []): string
    {
        $apiKey = config('services.ai.api_key');

        if (blank($apiKey)) {
            throw new RuntimeException('AI_API_KEY is not configured.');
        }

        $baseUrl = rtrim((string) config('services.ai.base_url'), '/');
        $model = (string) config('services.ai.model');
        $temperature = $options['temperature'] ?? 0.2;
        $timeout = $options['timeout'] ?? 120;
        $operation = (string) ($options['operation'] ?? 'chat_completion');

        $payload = [
            'model' => $model,
            'temperature' => $temperature,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        if (isset($options['max_tokens'])) {
            $payload['max_tokens'] = $options['max_tokens'];
        }

        $started = hrtime(true);

        try {
            $response = Http::timeout($timeout)
                ->withToken((string) $apiKey)
                ->acceptJson()
                ->post("{$baseUrl}/chat/completions", $payload);

            $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);

            if (! $response->successful()) {
                $this->logUsage(
                    operation: $operation,
                    model: $model,
                    response: $response,
                    latencyMs: $latencyMs,
                    success: false,
                    errorMessage: 'AI provider request failed: '.$response->body(),
                );
                throw new RuntimeException('AI provider request failed: '.$response->body());
            }

            $content = data_get($response->json(), 'choices.0.message.content');

            if (! is_string($content) || blank($content)) {
                $this->logUsage(
                    operation: $operation,
                    model: $model,
                    response: $response,
                    latencyMs: $latencyMs,
                    success: false,
                    errorMessage: 'AI provider returned an empty response.',
                );
                throw new RuntimeException('AI provider returned an empty response.');
            }

            $this->logUsage(
                operation: $operation,
                model: $model,
                response: $response,
                latencyMs: $latencyMs,
                success: true,
            );

            return $content;
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);
            $this->logUsage(
                operation: $operation,
                model: $model,
                response: null,
                latencyMs: $latencyMs,
                success: false,
                errorMessage: $e->getMessage(),
            );
            throw $e;
        }
    }

    public function isConfigured(): bool
    {
        return filled(config('services.ai.api_key'));
    }

    /**
     * Transcribe audio via OpenAI-compatible /audio/transcriptions endpoint.
     */
    public function transcribe(string $absolutePath, string $originalFilename): string
    {
        $apiKey = config('services.ai.api_key');

        if (blank($apiKey)) {
            throw new RuntimeException('AI_API_KEY is not configured for transcription.');
        }

        $baseUrl = rtrim((string) config('services.ai.base_url'), '/');
        $model = (string) config('services.ai.transcription_model', 'whisper-1');
        $started = hrtime(true);

        try {
            $response = Http::timeout(300)
                ->withToken((string) $apiKey)
                ->attach('file', file_get_contents($absolutePath), $originalFilename)
                ->post("{$baseUrl}/audio/transcriptions", [
                    'model' => $model,
                ]);

            $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);

            if (! $response->successful()) {
                $this->logUsage(
                    operation: 'transcription',
                    model: $model,
                    response: $response,
                    latencyMs: $latencyMs,
                    success: false,
                    errorMessage: 'Transcription request failed: '.$response->body(),
                );
                throw new RuntimeException('Transcription request failed: '.$response->body());
            }

            $text = data_get($response->json(), 'text');

            if (! is_string($text) || blank($text)) {
                $this->logUsage(
                    operation: 'transcription',
                    model: $model,
                    response: $response,
                    latencyMs: $latencyMs,
                    success: false,
                    errorMessage: 'Transcription returned empty text.',
                );
                throw new RuntimeException('Transcription returned empty text.');
            }

            $this->logUsage(
                operation: 'transcription',
                model: $model,
                response: $response,
                latencyMs: $latencyMs,
                success: true,
            );

            return $text;
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);
            $this->logUsage(
                operation: 'transcription',
                model: $model,
                response: null,
                latencyMs: $latencyMs,
                success: false,
                errorMessage: $e->getMessage(),
            );
            throw $e;
        }
    }

    private function logUsage(
        string $operation,
        string $model,
        ?Response $response,
        int $latencyMs,
        bool $success,
        ?string $errorMessage = null,
    ): void {
        $json = $response?->json() ?? [];
        $usage = is_array(data_get($json, 'usage')) ? data_get($json, 'usage') : [];

        try {
            AiUsageLog::query()->create([
                'provider' => $this->detectProvider(),
                'operation' => $operation,
                'model' => data_get($json, 'model') ?: $model,
                'success' => $success,
                'prompt_tokens' => $this->nullableInt(data_get($usage, 'prompt_tokens')),
                'completion_tokens' => $this->nullableInt(data_get($usage, 'completion_tokens')),
                'total_tokens' => $this->nullableInt(data_get($usage, 'total_tokens')),
                'latency_ms' => $latencyMs,
                'http_status' => $response?->status(),
                'ratelimit_limit_requests' => $this->headerInt($response, 'x-ratelimit-limit-requests'),
                'ratelimit_remaining_requests' => $this->headerInt($response, 'x-ratelimit-remaining-requests'),
                'ratelimit_limit_tokens' => $this->headerInt($response, 'x-ratelimit-limit-tokens'),
                'ratelimit_remaining_tokens' => $this->headerInt($response, 'x-ratelimit-remaining-tokens'),
                'ratelimit_reset_requests' => $response?->header('x-ratelimit-reset-requests') ?: null,
                'ratelimit_reset_tokens' => $response?->header('x-ratelimit-reset-tokens') ?: null,
                'error_message' => $errorMessage !== null ? mb_substr($errorMessage, 0, 2000) : null,
            ]);
        } catch (Throwable) {
            // Never fail the AI call because usage logging failed.
        }
    }

    private function detectProvider(): string
    {
        $baseUrl = strtolower((string) config('services.ai.base_url'));

        if (str_contains($baseUrl, 'groq.com')) {
            return 'groq';
        }

        if (str_contains($baseUrl, 'openai.com')) {
            return 'openai';
        }

        return 'openai_compatible';
    }

    private function headerInt(?Response $response, string $header): ?int
    {
        if (! $response) {
            return null;
        }

        $value = $response->header($header);

        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
