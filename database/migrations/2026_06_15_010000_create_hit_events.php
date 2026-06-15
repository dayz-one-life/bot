<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('victim_player_id')->nullable()->constrained('players')->nullOnDelete();
            $t->string('victim_gamertag');
            $t->string('attacker_gamertag')->nullable();
            $t->string('attacker_type'); // player | infected | animal | environment
            $t->string('attacker_label')->nullable();
            $t->string('body_part')->nullable();
            $t->integer('victim_hp')->nullable();
            $t->double('victim_x')->nullable();
            $t->double('victim_y')->nullable();
            $t->timestamp('occurred_at');
            $t->timestamps();
            $t->index('occurred_at');
            $t->index('victim_player_id');
            $t->index('attacker_gamertag');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hit_events');
    }
};
