<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_code_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pipeline_task_id')->unique();
            $table->string('branch_name');
            $table->string('commit_sha')->nullable();
            $table->unsignedInteger('pr_number')->nullable();
            $table->string('pr_url')->nullable();
            $table->string('pr_status', 32)->default('open');
            $table->longText('unified_diff')->nullable();
            $table->json('files_changed')->nullable();
            $table->timestamps();

            $table->foreign('pipeline_task_id')->references('id')->on('pipeline_tasks')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_code_changes');
    }
};
