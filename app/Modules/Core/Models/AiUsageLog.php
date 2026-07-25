<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiUsageLog extends Model
{
    use HasUuids;

    protected $table = 'ai_usage_logs';

    protected $fillable = [
        'provider',
        'operation',
        'model',
        'success',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'latency_ms',
        'http_status',
        'ratelimit_limit_requests',
        'ratelimit_remaining_requests',
        'ratelimit_limit_tokens',
        'ratelimit_remaining_tokens',
        'ratelimit_reset_requests',
        'ratelimit_reset_tokens',
        'error_message',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
            'latency_ms' => 'integer',
            'http_status' => 'integer',
            'ratelimit_limit_requests' => 'integer',
            'ratelimit_remaining_requests' => 'integer',
            'ratelimit_limit_tokens' => 'integer',
            'ratelimit_remaining_tokens' => 'integer',
        ];
    }
}
