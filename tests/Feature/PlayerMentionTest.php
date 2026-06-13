<?php

use App\Models\Player;
use App\Services\Lookup\PlayerMention;

beforeEach(fn () => $this->m = new PlayerMention());

it('mentions a linked player', function () {
    Player::create(['gamertag' => 'Linked', 'discord_user_id' => '12345', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    expect($this->m->for('Linked'))->toBe('<@12345>');
});

it('backticks an unlinked player', function () {
    Player::create(['gamertag' => 'Unlinked', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    expect($this->m->for('Unlinked'))->toBe('`Unlinked`');
});

it('backticks an unknown gamertag', function () {
    expect($this->m->for('Ghost'))->toBe('`Ghost`');
});

it('forPlayer uses the model without a lookup', function () {
    $linked = Player::create(['gamertag' => 'L', 'discord_user_id' => '999', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $unlinked = Player::create(['gamertag' => 'U', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    expect($this->m->forPlayer($linked))->toBe('<@999>');
    expect($this->m->forPlayer($unlinked))->toBe('`U`');
    expect($this->m->forPlayer(null, 'Fallback'))->toBe('`Fallback`');
});
