<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('srs_document_id');
            $table->unsignedInteger('version_number');
            $table->longText('generated_srs');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('srs_document_id')->references('id')->on('srs_documents')->cascadeOnDelete();
            $table->unique(['srs_document_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
