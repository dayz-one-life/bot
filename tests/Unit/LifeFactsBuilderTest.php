<?php

use App\Models\Life;
use App\Models\Player;
use App\Services\Lifecycle\LifeFactsBuilder;
use Carbon\CarbonImmutable;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-06-14T12:00:00Z'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('builds facts for a pvp death', function () {
    $p = Player::create(['gamertag' => 'Doomed', 'discord_user_id' => '123', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create([
        'player_id' => $p->id,
        'started_at' => '2026-06-14T11:13:00Z', // 47 min wall-clock
        'ended_at' => '2026-06-14T12:00:00Z',
        'death_cause' => 'pvp', 'death_by_gamertag' => 'Sniper', 'death_weapon' => 'SVD',
        'death_distance' => 312.5, 'playtime_seconds' => 2460, // 41 min
        'death_log' => "raw line A\nraw line B",
    ]);

    $facts = (new LifeFactsBuilder())->build($life);

    expect($facts['gamertag'])->toBe('Doomed');
    expect($facts['linked'])->toBeTrue();
    expect($facts['cause'])->toBe('pvp');
    expect($facts['killer'])->toBe('Sniper');
    expect($facts['weapon'])->toBe('SVD');
    expect($facts['distance_m'])->toBe(312.5);
    expect($facts['playtime_human'])->toContain('41'); // age == playtime (life clock), not wall-clock
    expect($facts['raw_log'])->toContain('raw line A');
    expect($facts['associates'])->toBeArray();
});

it('strips map coordinates from the raw log fed to the LLM', function () {
    $p = Player::create(['gamertag' => 'Loc', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create([
        'player_id' => $p->id, 'started_at' => '2026-06-14T11:00:00Z', 'ended_at' => '2026-06-14T11:30:00Z',
        'death_cause' => 'pvp', 'playtime_seconds' => 1800,
        'death_log' => '11:30 | Player "Loc" (id=L= pos=<5154.0, 1075.1, 56.3>) killed by Player "K" (id=K= pos=<900.0, 950.0, 5.0>) with M4A1 from 153.4 meters',
    ]);

    $facts = (new LifeFactsBuilder())->build($life);

    expect($facts['raw_log'])->not->toContain('pos=<');
    expect($facts['raw_log'])->not->toContain('5154');
    expect($facts['raw_log'])->not->toContain('950.0');
    expect($facts['raw_log'])->toContain('from 153.4 meters'); // distance kept — it's a range, not a location
    expect($facts['raw_log'])->toContain('killed by Player "K"');
});

it('offers recently-active players as witnesses, excluding the subject and killer', function () {
    $subject = Player::create(['gamertag' => 'Subject', 'first_seen_at' => '2026-06-01T00:00:00Z', 'last_seen_at' => '2026-06-14T12:00:00Z']);
    Player::create(['gamertag' => 'Sniper', 'first_seen_at' => '2026-06-01T00:00:00Z', 'last_seen_at' => '2026-06-14T12:00:00Z']);     // the killer
    Player::create(['gamertag' => 'ActiveBob', 'first_seen_at' => '2026-06-01T00:00:00Z', 'last_seen_at' => '2026-06-14T11:00:00Z']);  // active
    Player::create(['gamertag' => 'StaleSam', 'first_seen_at' => '2026-05-01T00:00:00Z', 'last_seen_at' => '2026-05-01T00:00:00Z']);   // inactive >14d

    $life = Life::create([
        'player_id' => $subject->id, 'started_at' => '2026-06-14T11:30:00Z', 'ended_at' => '2026-06-14T12:00:00Z',
        'death_cause' => 'pvp', 'death_by_gamertag' => 'Sniper', 'playtime_seconds' => 1800,
    ]);

    $facts = (new LifeFactsBuilder())->build($life);

    expect($facts['witnesses'])->toContain('ActiveBob');
    expect($facts['witnesses'])->not->toContain('Subject');  // not the subject
    expect($facts['witnesses'])->not->toContain('Sniper');   // not the killer
    expect($facts['witnesses'])->not->toContain('StaleSam'); // inactive (>14 days)
});

it('humanizes infected class names in the raw log fed to the LLM', function () {
    $p = Player::create(['gamertag' => 'Bitten', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create([
        'player_id' => $p->id, 'started_at' => '2026-06-14T11:00:00Z', 'ended_at' => '2026-06-14T11:30:00Z',
        'death_cause' => 'environment', 'playtime_seconds' => 1800,
        'death_log' => '11:30 | Player "Bitten" (DEAD) (id=B=) killed by ZmbM_JoggerSkinny_Red',
    ]);

    $facts = (new LifeFactsBuilder())->build($life);

    expect($facts['raw_log'])->not->toContain('ZmbM_');
    expect($facts['raw_log'])->toContain('an infected jogger');
});

it('shuffles witnesses so the same survivor is not always quoted first', function () {
    // Three recently-active survivors; recency order would be Alpha, Bravo, Charlie.
    Player::create(['gamertag' => 'Alpha', 'first_seen_at' => '2026-06-01T00:00:00Z', 'last_seen_at' => '2026-06-14T11:59:00Z']);
    Player::create(['gamertag' => 'Bravo', 'first_seen_at' => '2026-06-01T00:00:00Z', 'last_seen_at' => '2026-06-14T11:58:00Z']);
    Player::create(['gamertag' => 'Charlie', 'first_seen_at' => '2026-06-01T00:00:00Z', 'last_seen_at' => '2026-06-14T11:57:00Z']);
    $subject = Player::create(['gamertag' => 'Subject', 'first_seen_at' => '2026-06-01T00:00:00Z', 'last_seen_at' => '2026-06-14T12:00:00Z']);

    $life = Life::create([
        'player_id' => $subject->id, 'started_at' => '2026-06-14T11:30:00Z', 'ended_at' => '2026-06-14T12:00:00Z',
        'death_cause' => 'environment', 'playtime_seconds' => 1800,
    ]);

    // Inject a deterministic reversing "shuffle" to prove the order hook is applied (not raw recency).
    $facts = (new LifeFactsBuilder(null, fn (array $a) => array_reverse($a)))->build($life);

    expect($facts['witnesses'])->toBe(['Charlie', 'Bravo', 'Alpha']);
});

it('marks a sole life as the first life (no prior death)', function () {
    $p = Player::create(['gamertag' => 'Newbie', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $only = Life::create(['player_id' => $p->id, 'started_at' => '2026-06-14T11:50:00Z', 'playtime_seconds' => 360]);

    $facts = (new LifeFactsBuilder())->build($only);

    expect($facts['is_first_life'])->toBeTrue();
    expect($facts['prior_death'])->toBeNull();
});

it('summarises the prior death for a respawn and marks it NOT the first life', function () {
    $p = Player::create(['gamertag' => 'Reborn', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    Life::create(['player_id' => $p->id, 'started_at' => '2026-06-14T09:00:00Z', 'ended_at' => '2026-06-14T10:00:00Z', 'death_cause' => 'environment', 'playtime_seconds' => 3000]);
    $current = Life::create(['player_id' => $p->id, 'started_at' => '2026-06-14T11:50:00Z', 'playtime_seconds' => 360]);

    $facts = (new LifeFactsBuilder())->build($current);

    expect($facts['linked'])->toBeFalse();
    expect($facts['prior_death'])->toContain('environment');
    expect($facts['is_first_life'])->toBeFalse();
});

it('does NOT leak the prior killer gamertag into the prior-death summary', function () {
    // The prior killer's name must never appear verbatim in prior_death: the LLM is told never to
    // write a real name, so it converts any name it sees into the {{KILLER}} token — but a birth has
    // no killer to substitute, leaving a raw "{{KILLER}}" in the post. Keep the summary name-free.
    $p = Player::create(['gamertag' => 'Reborn', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    Life::create(['player_id' => $p->id, 'started_at' => '2026-06-14T09:00:00Z', 'ended_at' => '2026-06-14T10:00:00Z', 'death_cause' => 'pvp', 'death_by_gamertag' => 'PriorSniper', 'playtime_seconds' => 3000]);
    $current = Life::create(['player_id' => $p->id, 'started_at' => '2026-06-14T11:50:00Z', 'playtime_seconds' => 360]);

    $facts = (new LifeFactsBuilder())->build($current);

    expect($facts['prior_death'])->toContain('pvp');
    expect($facts['prior_death'])->not->toContain('PriorSniper');
});
