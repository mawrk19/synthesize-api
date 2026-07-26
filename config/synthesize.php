<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Client-facing debug mode
    |--------------------------------------------------------------------------
    |
    | When true, API resources may include detailed error messages, agent
    | prompt templates, and other internals useful for local development.
    | When false (recommended in production), those fields are redacted so
    | end users cannot read stack traces, provider payloads, or prompts.
    |
    | Falls back to APP_DEBUG when SYNTHESIZE_DEBUG is unset.
    |
    */
    'debug' => (bool) env('SYNTHESIZE_DEBUG', env('APP_DEBUG', false)),
];
