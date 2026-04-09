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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            // Nanti bisa ditambah user_id jika ada fitur login, untuk MVP biarkan begini dulu
            $table->string('repo_name'); // Contoh isian: "wahyu/backend-api"
            $table->string('project_token')->unique(); // Token unik untuk CLI
            $table->timestamps();
        });

        // Opsional: Tambahkan kolom project_id ke tabel scans yang sudah kamu buat sebelumnya
        // agar kita tahu scan ini milik project yang mana
            Schema::table('scans', function (Blueprint $table) {
                $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('cascade');
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Schema::dropIfExists('projects');
        Schema::table('scans', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropColumn('project_id');
        });
        Schema::dropIfExists('projects');
    }
};
