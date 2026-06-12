<?php

use Illuminate\Support\Facades\Schema;

it('creates all one-life tables', function () {
    foreach (['players','adm_files','bans','lives','game_sessions','bot_state'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});

it('adds ban_issued to lives', function () {
    expect(Illuminate\Support\Facades\Schema::hasColumn('lives', 'ban_issued'))->toBeTrue();
});

it('creates the bounty tables', function () {
    foreach (['player_positions', 'associate_overrides', 'bounties'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
    expect(Schema::hasColumn('bounties', 'token_awarded'))->toBeTrue();
    expect(Schema::hasColumn('bounties', 'end_reason'))->toBeTrue();
});
