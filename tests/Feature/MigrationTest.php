<?php

use Illuminate\Support\Facades\Schema;

it('creates all one-life tables', function () {
    foreach (['players','adm_files','bans','lives','game_sessions','bot_state'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});
