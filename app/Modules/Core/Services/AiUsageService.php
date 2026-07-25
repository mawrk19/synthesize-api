<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\AiUsageLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AiUsageService
{
    /**
     * Aggregate AI/Groq usage for the dashboard.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $configured = filled(config('services.ai.api_key'));
        $provider = $this->detectProvider();
        $model = (string) config('services.ai.model');
        $todayStart = Carbon::now()->startOfDay();
        $weekStart = Carbon::now()->subDays(6)->startOfDay();

        $today = $this->periodStats($todayStart);
        $week = $this->periodStats($weekStart);

        $latestWithLimits = AiUsageLog::query()
            ->where(function ($query) {
                $query->whereNotNull('ratelimit_remaining_requests')
                    ->orWhereNotNull('ratelimit_remaining_tokens');
            })
            ->orderByDesc('created_at')
            ->first();

        $recent = AiUsageLog::query()
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(fn (AiUsageLog $log) => [
                'id' => $log->id,
                'operation' => $log->operation,
                'model' => $log->model,
                'success' => $log->success,
                'total_tokens' => $log->total_tokens,
                'prompt_tokens' => $log->prompt_tokens,
                'completion_tokens' => $log->completion_tokens,
                'latency_ms' => $log->latency_ms,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->all();

        $byOperation = AiUsageLog::query()
            ->where('created_at', '>=', $weekStart)
            ->select('operation', DB::raw('COUNT(*) as calls'), DB::raw('COALESCE(SUM(total_tokens), 0) as tokens'))
            ->groupBy('operation')
            ->get()
            ->map(fn ($row) => [
                'operation' => $row->operation,
                'calls' => (int) $row->calls,
                'tokens' => (int) $row->tokens,
            ])
            ->all();

        return [
            'configured' => $configured,
            'provider' => $provider,
            'model' => $model,
            'console_url' => $provider === 'groq'
                ? 'https://console.groq.com/settings/limits'
                : null,
            'today' => $today,
            'last_7_days' => $week,
            'rate_limits' => $latestWithLimits ? [
                'limit_requests' => $latestWithLimits->ratelimit_limit_requests,
                'remaining_requests' => $latestWithLimits->ratelimit_remaining_requests,
                'limit_tokens' => $latestWithLimits->ratelimit_limit_tokens,
                'remaining_tokens' => $latestWithLimits->ratelimit_remaining_tokens,
                'reset_requests' => $latestWithLimits->ratelimit_reset_requests,
                'reset_tokens' => $latestWithLimits->ratelimit_reset_tokens,
                'observed_at' => $latestWithLimits->created_at?->toIso8601String(),
            ] : null,
            'by_operation' => $byOperation,
            'recent' => $recent,
        ];
    }

    /**
     * @return array{calls: int, successful: int, failed: int, prompt_tokens: int, completion_tokens: int, total_tokens: int, avg_latency_ms: int|null}
     */
    private function periodStats(Carbon $since): array
    {
        $row = AiUsageLog::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw('SUM(CASE WHEN success = ? THEN 1 ELSE 0 END) as successful', [true])
            ->selectRaw('SUM(CASE WHEN success = ? THEN 1 ELSE 0 END) as failed', [false])
            ->selectRaw('COALESCE(SUM(prompt_tokens), 0) as prompt_tokens')
            ->selectRaw('COALESCE(SUM(completion_tokens), 0) as completion_tokens')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->selectRaw('AVG(latency_ms) as avg_latency_ms')
            ->first();

        return [
            'calls' => (int) ($row->calls ?? 0),
            'successful' => (int) ($row->successful ?? 0),
            'failed' => (int) ($row->failed ?? 0),
            'prompt_tokens' => (int) ($row->prompt_tokens ?? 0),
            'completion_tokens' => (int) ($row->completion_tokens ?? 0),
            'total_tokens' => (int) ($row->total_tokens ?? 0),
            'avg_latency_ms' => $row->avg_latency_ms !== null ? (int) round((float) $row->avg_latency_ms) : null,
        ];
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
}
