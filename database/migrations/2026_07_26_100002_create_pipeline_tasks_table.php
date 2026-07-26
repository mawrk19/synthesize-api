<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pipeline_run_id');
            $table->uuid('project_id');
            $table->uuid('requirement_id')->nullable();
            $table->uuid('parent_task_id')->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('agent_role', 32);
            $table->string('status', 32)->default('pending');
            $table->text('prompt_template')->nullable();
            $table->json('files_hint')->nullable();
            $table->uuid('depends_on_task_id')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->longText('audit_report')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('pipeline_run_id')->references('id')->on('pipeline_runs')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('requirement_id')->references('id')->on('requirements')->nullOnDelete();
            $table->index(['pipeline_run_id', 'agent_role', 'status']);
            $table->index(['project_id', 'status']);
            $table->index(['pipeline_run_id', 'sort_order']);
            $table->index('parent_task_id');
            $table->index('depends_on_task_id');
        });

        Schema::table('pipeline_tasks', function (Blueprint $table) {
            $table->foreign('parent_task_id')->references('id')->on('pipeline_tasks')->nullOnDelete();
            $table->foreign('depends_on_task_id')->references('id')->on('pipeline_tasks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_tasks');
    }
};
