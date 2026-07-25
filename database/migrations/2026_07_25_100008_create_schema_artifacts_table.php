<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schema_artifacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('srs_document_id')->nullable();
            $table->longText('ddl_sql')->nullable();
            $table->longText('openapi_json')->nullable();
            $table->string('status')->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('srs_document_id')->references('id')->on('srs_documents')->nullOnDelete();
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schema_artifacts');
    }
};
