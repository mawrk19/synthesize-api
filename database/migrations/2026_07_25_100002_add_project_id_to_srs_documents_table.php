<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('srs_documents', function (Blueprint $table) {
            $table->uuid('project_id')->nullable()->after('user_id');
        });

        $userIds = DB::table('srs_documents')->distinct()->pluck('user_id');

        foreach ($userIds as $userId) {
            $projectId = (string) Str::uuid();
            $now = now();

            DB::table('projects')->insert([
                'id' => $projectId,
                'user_id' => $userId,
                'name' => 'Default Project',
                'description' => 'Auto-created for existing SRS documents.',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('srs_documents')
                ->where('user_id', $userId)
                ->whereNull('project_id')
                ->update(['project_id' => $projectId]);
        }

        // Also create a default project for users who have no documents yet is skipped;
        // projects are created on demand.

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE srs_documents MODIFY project_id CHAR(36) NOT NULL');
        }

        Schema::table('srs_documents', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('srs_documents', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropIndex(['project_id', 'created_at']);
            $table->dropColumn('project_id');
        });
    }
};
