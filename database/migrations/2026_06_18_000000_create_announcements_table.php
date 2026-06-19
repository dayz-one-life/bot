<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $t) {
            $t->id();
            // cascade: an announcement is meaningless once its life is gone.
            $t->foreignId('life_id')->constrained()->cascadeOnDelete();
            $t->string('kind');              // 'birth' | 'eulogy'
            $t->text('headline');
            $t->text('body');
            $t->boolean('was_fallback')->default(false); // true => LLM failed, canned copy used
            $t->string('model')->nullable();             // e.g. 'anthropic/claude-haiku-4.5'; null when fallback
            $t->timestamps();
            $t->index(['life_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
