<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_tasks', function (Blueprint $table) {
            $table->boolean('included_in_plan')->default(true)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_tasks', function (Blueprint $table) {
            $table->dropColumn('included_in_plan');
        });
    }
};
