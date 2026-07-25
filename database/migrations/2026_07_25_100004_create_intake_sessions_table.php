<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->string('title')->nullable();
            $table->string('source_type')->default('brain_dump'); // brain_dump | transcript | audio
            $table->longText('raw_content')->nullable();
            $table->json('structured_draft')->nullable();
            $table->string('status')->default('draft')->index();
            $table->string('audio_path')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_sessions');
    }
};
