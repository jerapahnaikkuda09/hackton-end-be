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
        Schema::create('pr_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained('scans')->onDelete('cascade');
            $table->integer('pr_number');
            $table->string('repository');
            $table->text('comment_body');
            $table->string('github_comment_id')->nullable();
            $table->enum('status', ['pending', 'posted', 'failed'])->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pr_comments');
    }
};
