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
        Schema::table('group_members', function (Blueprint $table) {

            $table->enum(
                'topic2_confirmation_status',
                ['pending', 'confirmed', 'rejected']
            )
            ->default('pending')
            ->after('username_std');

            $table->timestamp('topic2_confirmed_at')
                ->nullable()
                ->after('topic2_confirmation_status');

            $table->text('confirmation_remark')
                ->nullable()
                ->after('topic2_confirmed_at');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('group_members', function (Blueprint $table) {

            $table->dropColumn([
                'topic2_confirmation_status',
                'topic2_confirmed_at',
                'confirmation_remark'
            ]);

        });
    }
};