<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_repositories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id')->unique();
            $table->string('provider', 32)->default('github');
            $table->string('owner');
            $table->string('repo');
            $table->string('default_branch')->default('main');
            $table->text('encrypted_token')->nullable();
            $table->string('base_path')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_repositories');
    }
};
