<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $t) {
            $t->id();
            $t->string('gamertag')->unique();
            $t->string('discord_user_id')->nullable()->unique();
            $t->foreignId('referrer_id')->nullable()->constrained('players')->nullOnDelete();
            $t->unsignedInteger('unban_tokens')->default(0);
            $t->unsignedInteger('used_tokens')->default(0);
            $t->boolean('link_rewarded')->default(false);
            $t->timestamp('first_seen_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
            $t->index('last_seen_at');
        });

        Schema::create('adm_files', function (Blueprint $t) {
            $t->id();
            $t->string('path')->unique();
            $t->string('name');
            $t->timestamp('log_date')->nullable();
            $t->boolean('is_complete')->default(false);
            $t->unsignedInteger('last_processed_line')->default(0);
            $t->unsignedBigInteger('last_known_size')->default(0);
            $t->timestamps();
        });

        Schema::create('bans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('player_id')->constrained()->cascadeOnDelete();
            $t->timestamp('banned_at');
            $t->timestamp('expires_at')->nullable();
            $t->boolean('expired')->default(false);
            $t->string('reason');
            $t->string('source')->default('manual'); // auto_death | manual | token
            $t->timestamps();
            $t->index('expired');
            $t->index('expires_at');
        });

        Schema::create('lives', function (Blueprint $t) {
            $t->id();
            $t->foreignId('player_id')->constrained()->cascadeOnDelete();
            $t->timestamp('started_at');
            $t->timestamp('ended_at')->nullable();
            $t->string('death_cause')->nullable();
            $t->string('death_by_gamertag')->nullable();
            $t->unsignedBigInteger('playtime_seconds')->default(0);
            $t->timestamps();
            $t->index(['player_id', 'ended_at']);
        });

        Schema::create('game_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('player_id')->constrained()->cascadeOnDelete();
            $t->foreignId('life_id')->constrained('lives')->cascadeOnDelete();
            $t->timestamp('connected_at');
            $t->timestamp('disconnected_at')->nullable();
            $t->unsignedBigInteger('duration_seconds')->nullable();
            $t->string('close_reason')->nullable(); // clean | reboot | superseded
            $t->timestamps();
            $t->index(['player_id', 'disconnected_at']);
        });

        Schema::create('bot_state', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['game_sessions','lives','bans','adm_files','bot_state','players'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
