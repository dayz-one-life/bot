<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lives', function (Blueprint $t) {
            $t->string('death_weapon')->nullable()->after('death_by_gamertag');
            $t->float('death_distance')->nullable()->after('death_weapon');
        });
    }

    public function down(): void
    {
        Schema::table('lives', function (Blueprint $t) {
            $t->dropColumn(['death_weapon', 'death_distance']);
        });
    }
};
