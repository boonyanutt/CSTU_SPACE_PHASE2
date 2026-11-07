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
       Schema::create('student_enrollments', function (Blueprint $table) {
    $table->id('enrollment_id');

    $table->unsignedInteger('student_id');
    $table->string('subject_code', 20);
    $table->integer('year');
    $table->tinyInteger('semester');

    $table->string('grade', 5)->nullable();
    $table->unsignedBigInteger('project_id')->nullable();

    $table->timestamps();

    $table->foreign('student_id')
        ->references('student_id')
        ->on('student');

    $table->foreign('project_id')
        ->references('project_id')
        ->on('projects');

    $table->index(['subject_code', 'year', 'semester']);
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_enrollments');
    }
};
