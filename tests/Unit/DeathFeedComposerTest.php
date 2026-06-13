<?php

use App\Models\Life;
use App\Models\Player;
use App\Services\DeathFeed\DeathFeedComposer;
use App\Services\Personality\MessagePicker;
use Carbon\CarbonImmutable;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

// Deterministic picker: always the first line of the pool.
function fixedPicker(): MessagePicker {
    MessagePicker::reset();
    return new MessagePicker(fn (array $pool, ?int $avoid) => 0);
}

function lifeFor(array $attrs): Life {
    $p = Player::create(['gamertag' => $attrs['tag'] ?? 'Victim', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    return Life::create(array_merge([
        'player_id' => $p->id,
        'started_at' => now()->subHour(),
        'ended_at' => now(),
        'death_cause' => 'pvp',
    ], $attrs['life'] ?? []));
}

beforeEach(fn () => CarbonImmutable::setTestNow('2026-06-13T12:00:00Z'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('selects the pvp pool and renders weapon, distance, and a relative expiry', function () {
    $life = lifeFor(['tag' => 'Victim', 'life' => [
        'death_cause' => 'pvp', 'death_by_gamertag' => 'Killer',
        'death_weapon' => 'SVD', 'death_distance' => 243.4,
    ]]);
    $expires = CarbonImmutable::now()->addHours(12);

    $msg = (new DeathFeedComposer(fixedPicker()))->compose($life, $expires);

    expect($msg)->toContain('SVD');
    expect($msg)->toContain('243m');                 // distance rounded, no decimals
    expect($msg)->toContain("<t:{$expires->timestamp}:R>");
    expect($msg)->toContain('`Victim`');             // unlinked victim → backticked
    expect($msg)->toContain('`Killer`');             // unlinked killer → backticked
});

it('uses the pvp_noweapon pool when a killer is known but no weapon', function () {
    $life = lifeFor(['tag' => 'Victim', 'life' => [
        'death_cause' => 'pvp', 'death_by_gamertag' => 'Killer',
        'death_weapon' => null, 'death_distance' => null,
    ]]);

    $msg = (new DeathFeedComposer(fixedPicker()))->compose($life, CarbonImmutable::now());

    expect($msg)->toContain('`Killer`');
    expect($msg)->toContain('put `Victim` in the dirt');
});

it('uses the suicide pool', function () {
    $life = lifeFor(['life' => ['death_cause' => 'suicide', 'death_by_gamertag' => null]]);
    $msg = (new DeathFeedComposer(fixedPicker()))->compose($life, CarbonImmutable::now());
    expect($msg)->toContain('rage-quit life itself');
});

it('uses the environment pool', function () {
    $life = lifeFor(['life' => ['death_cause' => 'environment', 'death_by_gamertag' => null]]);
    $msg = (new DeathFeedComposer(fixedPicker()))->compose($life, CarbonImmutable::now());
    expect($msg)->toContain('The map itself claimed');
});

it('uses the misc pool with a humanized cause', function () {
    $life = lifeFor(['life' => ['death_cause' => 'bled_out', 'death_by_gamertag' => null]]);
    $msg = (new DeathFeedComposer(fixedPicker()))->compose($life, CarbonImmutable::now());
    expect($msg)->toContain('bled out');             // humanized from 'bled_out'
});

it('mentions a linked victim instead of backticking', function () {
    $life = lifeFor(['tag' => 'Linked', 'life' => ['death_cause' => 'drowned']]);
    $life->player->update(['discord_user_id' => '999']);

    $msg = (new DeathFeedComposer(fixedPicker()))->compose($life->fresh(), CarbonImmutable::now());
    expect($msg)->toContain('<@999>');
});
