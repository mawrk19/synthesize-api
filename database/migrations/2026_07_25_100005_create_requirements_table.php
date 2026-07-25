<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('srs_document_id')->nullable();
            $table->string('type'); // fr | nfr | story
            $table->string('code');
            $table->string('title');
            $table->longText('body')->nullable();
            $table->string('priority')->nullable();
            $table->longText('gherkin')->nullable();
            $table->json('validation_flags')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('srs_document_id')->references('id')->on('srs_documents')->nullOnDelete();
            $table->index(['project_id', 'type']);
            $table->unique(['project_id', 'code', 'srs_document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requirements');
    }
};
