<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lives', function (Blueprint $t) {
            $t->text('death_log')->nullable()->after('death_distance');
            $t->timestamp('birth_announced_at')->nullable()->after('death_log');
            $t->boolean('eulogy_posted')->default(false)->after('birth_announced_at');
        });
    }

    public function down(): void
    {
        Schema::table('lives', function (Blueprint $t) {
            $t->dropColumn(['death_log', 'birth_announced_at', 'eulogy_posted']);
        });
    }
};
