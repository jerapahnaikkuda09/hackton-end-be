<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('repo_url', 500)->nullable()->index();  // ← tambah ini
            $table->string('repository')->nullable();
            $table->string('branch')->nullable();
            $table->string('commit_hash')->nullable();
            $table->enum('source', ['local', 'github_action'])->default('local');
            $table->integer('pr_number')->nullable();
            $table->json('issues')->nullable();
            $table->integer('total_critical')->default(0);
            $table->integer('total_warning')->default(0);
            $table->integer('total_info')->default(0);
            $table->enum('max_severity', ['none', 'info', 'warning', 'critical'])->default('none');
            $table->boolean('blocked')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scans');
    }
};
