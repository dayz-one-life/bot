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

it('counts the world the player spawned into, excluding their own session', function () {
    $subject = Player::create(['gamertag' => 'New', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $a = Player::create(['gamertag' => 'A', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $b = Player::create(['gamertag' => 'B', 'first_seen_at' => now(), 'last_seen_at' => now()]);

    $life = Life::create(['player_id' => $subject->id, 'started_at' => '2026-06-14T12:00:00Z', 'playtime_seconds' => 0]);

    // A is online across the spawn instant (open session) -> counts.
    \App\Models\GameSession::create(['player_id' => $a->id, 'life_id' => $life->id, 'connected_at' => '2026-06-14T11:50:00Z', 'disconnected_at' => null]);
    // B logged out before spawn -> does not count.
    \App\Models\GameSession::create(['player_id' => $b->id, 'life_id' => $life->id, 'connected_at' => '2026-06-14T10:00:00Z', 'disconnected_at' => '2026-06-14T11:00:00Z']);
    // Subject's own open session -> excluded.
    \App\Models\GameSession::create(['player_id' => $subject->id, 'life_id' => $life->id, 'connected_at' => '2026-06-14T12:00:00Z', 'disconnected_at' => null]);

    $facts = (new LifeFactsBuilder())->build($life);

    expect($facts['population_at_spawn'])->toBe(1); // only A
});

it('counts births and deaths in the 7 days before the spawn', function () {
    $p = Player::create(['gamertag' => 'P', 'first_seen_at' => now(), 'last_seen_at' => now()]);

    // Within the window [spawn-7d, spawn): 2 births, 1 death.
    Life::create(['player_id' => $p->id, 'started_at' => '2026-06-10T00:00:00Z', 'ended_at' => '2026-06-11T00:00:00Z', 'death_cause' => 'pvp', 'playtime_seconds' => 60]);
    Life::create(['player_id' => $p->id, 'started_at' => '2026-06-12T00:00:00Z', 'playtime_seconds' => 60]);
    // Outside the window (older than 7 days): ignored.
    Life::create(['player_id' => $p->id, 'started_at' => '2026-06-01T00:00:00Z', 'ended_at' => '2026-06-02T00:00:00Z', 'death_cause' => 'pvp', 'playtime_seconds' => 60]);

    $life = Life::create(['player_id' => $p->id, 'started_at' => '2026-06-14T12:00:00Z', 'playtime_seconds' => 0]);

    $facts = (new LifeFactsBuilder())->build($life);

    expect($facts['births_this_week'])->toBe(2); // the two inside the window (the subject's own life starts AT the boundary, excluded by `<`)
    expect($facts['deaths_this_week'])->toBe(1); // only the 06-11 death (06-02 is >7d before)
});

it('buckets the spawn hour into a time of day', function () {
    $p = Player::create(['gamertag' => 'Q', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $cases = [
        '2026-06-14T06:00:00Z' => 'dawn',
        '2026-06-14T12:00:00Z' => 'day',
        '2026-06-14T18:00:00Z' => 'dusk',
        '2026-06-14T23:00:00Z' => 'night',
        '2026-06-14T03:00:00Z' => 'night',
    ];
    foreach ($cases as $ts => $expected) {
        $life = Life::create(['player_id' => $p->id, 'started_at' => $ts, 'playtime_seconds' => 0]);
        expect((new LifeFactsBuilder())->build($life)['time_of_day'])->toBe($expected);
    }
});
