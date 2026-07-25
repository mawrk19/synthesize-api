<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider')->default('groq')->index();
            $table->string('operation')->index(); // chat_completion | transcription
            $table->string('model')->nullable();
            $table->boolean('success')->default(true)->index();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('http_status')->nullable();
            $table->unsignedInteger('ratelimit_limit_requests')->nullable();
            $table->unsignedInteger('ratelimit_remaining_requests')->nullable();
            $table->unsignedInteger('ratelimit_limit_tokens')->nullable();
            $table->unsignedInteger('ratelimit_remaining_tokens')->nullable();
            $table->string('ratelimit_reset_requests')->nullable();
            $table->string('ratelimit_reset_tokens')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
