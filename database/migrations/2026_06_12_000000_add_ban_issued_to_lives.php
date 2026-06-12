<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lives', function (Blueprint $t) {
            $t->boolean('ban_issued')->default(false)->after('death_by_gamertag');
            $t->index(['ended_at', 'ban_issued']);
        });
    }

    public function down(): void
    {
        Schema::table('lives', function (Blueprint $t) {
            $t->dropIndex(['ended_at', 'ban_issued']);
            $t->dropColumn('ban_issued');
        });
    }
};
