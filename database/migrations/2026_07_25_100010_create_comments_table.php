<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('requirement_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guest_name')->nullable();
            $table->text('body');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('requirement_id')->references('id')->on('requirements')->cascadeOnDelete();
            $table->index(['requirement_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
