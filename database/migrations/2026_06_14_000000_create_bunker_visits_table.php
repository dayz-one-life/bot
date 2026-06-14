<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bunker_visits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('player_id')->constrained()->cascadeOnDelete();
            // nullOnDelete (not cascade): a visit is a historical record that outlives its life; a null life_id still counts toward total-visit boards but is excluded from "quickest to bunker".
            $t->foreignId('life_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('visited_at');
            $t->timestamps();
            $t->index(['player_id', 'visited_at']);
            $t->index('visited_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bunker_visits');
    }
};
