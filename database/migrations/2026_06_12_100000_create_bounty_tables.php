<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_positions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('player_id')->constrained()->cascadeOnDelete();
            $t->double('x');
            $t->double('y');
            $t->timestamp('recorded_at');
            $t->timestamps();
            $t->index(['player_id', 'recorded_at']);
            $t->index('recorded_at');
        });

        Schema::create('associate_overrides', function (Blueprint $t) {
            $t->id();
            $t->foreignId('player_a_id')->constrained('players')->cascadeOnDelete();
            $t->foreignId('player_b_id')->constrained('players')->cascadeOnDelete();
            $t->boolean('force'); // true = always associates, false = never associates
            $t->timestamps();
            $t->unique(['player_a_id', 'player_b_id']);
        });

        Schema::create('bounties', function (Blueprint $t) {
            $t->id();
            $t->foreignId('player_id')->constrained()->cascadeOnDelete();
            $t->foreignId('life_id')->constrained('lives')->cascadeOnDelete();
            $t->timestamp('placed_at');
            $t->timestamp('ended_at')->nullable();
            $t->string('end_reason')->nullable(); // moved | claimed | claimed_by_associate | died | inactive
            $t->foreignId('claimed_by_player_id')->nullable()->constrained('players')->nullOnDelete();
            $t->boolean('token_awarded')->default(false);
            $t->timestamps();
            $t->index('ended_at');
        });
    }

    public function down(): void
    {
        foreach (['bounties', 'associate_overrides', 'player_positions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
