<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Provider (OpenAI-compatible: OpenAI, Groq, etc.)
    |--------------------------------------------------------------------------
    |
    | Leave AI_API_KEY empty to use the local structured fallback generator.
    |
    */
    'ai' => [
        'api_key' => env('AI_API_KEY'),
        'base_url' => env('AI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('AI_MODEL', 'gpt-4o-mini'),
        // Transcription defaults to Groq Whisper (free tier). Override for OpenAI etc.
        'transcription_api_key' => env('AI_TRANSCRIPTION_API_KEY'),
        'transcription_base_url' => env('AI_TRANSCRIPTION_BASE_URL'),
        'transcription_model' => env('AI_TRANSCRIPTION_MODEL'),
    ],

    'github' => [
        'default_token' => env('GITHUB_DEFAULT_TOKEN'),
        'api_base_url' => env('GITHUB_API_BASE_URL', 'https://api.github.com'),
    ],

    'pipeline' => [
        'max_tasks_per_run' => (int) env('PIPELINE_MAX_TASKS_PER_RUN', 10),
        'tick_delay_seconds' => (int) env('PIPELINE_TICK_DELAY_SECONDS', 5),
    ],

];
