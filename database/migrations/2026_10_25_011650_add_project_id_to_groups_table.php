<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable();
        });

        // เชื่อมกลุ่มเก่ากับ Project เดิม
        DB::statement('
            UPDATE `groups` AS g
            INNER JOIN `projects` AS p
                ON p.group_id = g.group_id
            SET g.project_id = p.project_id
        ');

        Schema::table('groups', function (Blueprint $table) {
            $table->foreign('project_id')
                ->references('project_id')
                ->on('projects')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropColumn('project_id');
        });
    }
};