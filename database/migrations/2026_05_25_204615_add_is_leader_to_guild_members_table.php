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
        Schema::table('guild_members', function (Blueprint $table) {
            $table->boolean('is_leader')->default(false);
            $table->index(['guild_id', 'is_leader'], 'guild_members_guild_id_is_leader_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guild_members', function (Blueprint $table) {
            $table->dropIndex('guild_members_guild_id_is_leader_index');
            $table->dropColumn('is_leader');
        });
    }
};
