<?php

use App\Models\Ban;
use App\Models\Player;
use App\Services\Lookup\GamertagLookup;
use Carbon\CarbonImmutable;

beforeEach(fn () => $this->lookup = new GamertagLookup());

it('returns a native array list of gamertags (not a Collection)', function () {
    Player::create(['gamertag' => 'Alice', 'first_seen_at' => now(), 'last_seen_at' => now()]);

    $result = $this->lookup->players();

    // The root cause of "Loading options failed": Laracord's Arr::isList() throws
    // on a Collection. The provider MUST return a plain array list.
    expect($result)->toBeArray();
    expect(array_is_list($result))->toBeTrue();
    expect($result)->toContain('Alice');
});

it('filters players by linked status', function () {
    Player::create(['gamertag' => 'Linked', 'discord_user_id' => 'd-1', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    Player::create(['gamertag' => 'Unlinked', 'first_seen_at' => now(), 'last_seen_at' => now()]);

    expect($this->lookup->players(linked: true))->toBe(['Linked']);
    expect($this->lookup->players(linked: false))->toBe(['Unlinked']);
    expect($this->lookup->players())->toHaveCount(2);
});

it('filters players by search substring', function () {
    Player::create(['gamertag' => 'CrazyAlex', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    Player::create(['gamertag' => 'BobSmith', 'first_seen_at' => now(), 'last_seen_at' => now()]);

    expect($this->lookup->players('alex'))->toBe(['CrazyAlex']);
});

it('returns only currently temporary-banned gamertags when temporaryOnly', function () {
    $now = CarbonImmutable::parse('2026-06-12T12:00:00Z');
    CarbonImmutable::setTestNow($now);

    $temp = Player::create(['gamertag' => 'TempBanned', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $perm = Player::create(['gamertag' => 'PermBanned', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $expired = Player::create(['gamertag' => 'ExpiredBan', 'first_seen_at' => now(), 'last_seen_at' => now()]);

    Ban::create(['player_id' => $temp->id, 'banned_at' => $now, 'expires_at' => $now->addHours(6), 'expired' => false, 'reason' => 'death']);
    Ban::create(['player_id' => $perm->id, 'banned_at' => $now, 'expires_at' => null, 'expired' => false, 'reason' => 'manual']);
    Ban::create(['player_id' => $expired->id, 'banned_at' => $now->subDay(), 'expires_at' => $now->subHour(), 'expired' => false, 'reason' => 'death']);

    $result = $this->lookup->bannedGamertags(temporaryOnly: true);

    expect($result)->toBeArray();
    expect($result)->toBe(['TempBanned']);

    CarbonImmutable::setTestNow();
});

it('includes permanent active bans for admin (temporaryOnly false)', function () {
    $now = CarbonImmutable::parse('2026-06-12T12:00:00Z');
    CarbonImmutable::setTestNow($now);

    $temp = Player::create(['gamertag' => 'TempBanned', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $perm = Player::create(['gamertag' => 'PermBanned', 'first_seen_at' => now(), 'last_seen_at' => now()]);

    Ban::create(['player_id' => $temp->id, 'banned_at' => $now, 'expires_at' => $now->addHours(6), 'expired' => false, 'reason' => 'death']);
    Ban::create(['player_id' => $perm->id, 'banned_at' => $now, 'expires_at' => null, 'expired' => false, 'reason' => 'manual']);

    $result = $this->lookup->bannedGamertags();

    expect($result)->toBeArray();
    expect($result)->toContain('TempBanned')->toContain('PermBanned');

    CarbonImmutable::setTestNow();
});
