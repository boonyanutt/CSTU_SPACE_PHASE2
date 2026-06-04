<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_activity_logs', function (Blueprint $table) {
            $table->id('activity_log_id');

            $table->string('username', 100);
            $table->string('user_type', 50)->nullable(); // user / student
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('student_id')->nullable();

            $table->string('role', 50)->nullable();

            $table->string('action', 100); // create_group, update_project, import_excel
            $table->string('module', 100); // group, project, proposal, user
            $table->string('target_type', 100)->nullable(); // groups, projects, students
            $table->unsignedBigInteger('target_id')->nullable();

            $table->text('description')->nullable();

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index(['username', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['module', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_activity_logs');
    }
};