<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_links', function (Blueprint $table) {
            $table->uuid('pipeline_run_id')->nullable()->after('project_id');
            $table->string('approval_status', 32)->default('pending')->after('allow_comment');
            $table->timestamp('approved_at')->nullable()->after('approval_status');
            $table->string('approved_by_name')->nullable()->after('approved_at');

            $table->foreign('pipeline_run_id')->references('id')->on('pipeline_runs')->nullOnDelete();
            $table->index(['pipeline_run_id', 'approval_status']);
        });
    }

    public function down(): void
    {
        Schema::table('review_links', function (Blueprint $table) {
            $table->dropForeign(['pipeline_run_id']);
            $table->dropIndex(['pipeline_run_id', 'approval_status']);
            $table->dropColumn([
                'pipeline_run_id',
                'approval_status',
                'approved_at',
                'approved_by_name',
            ]);
        });
    }
};
