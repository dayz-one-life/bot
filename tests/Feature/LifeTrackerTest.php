<?php

use App\Models\Player;
use App\Services\Life\LifeTracker;

function at(string $iso): DateTimeImmutable { return new DateTimeImmutable($iso); }

beforeEach(fn () => $this->tracker = new LifeTracker());

it('opens a life and session on first connect', function () {
    $this->tracker->connect('Alice', at('2026-06-11T10:00:00Z'));

    $player = Player::where('gamertag', 'Alice')->first();
    expect($player)->not->toBeNull();
    expect($player->first_seen_at->toIso8601String())->toBe(at('2026-06-11T10:00:00Z')->format('c'));
    expect($player->openLife())->not->toBeNull();
    expect($player->openSession())->not->toBeNull();
});

it('closes the session and accrues playtime on disconnect, keeping the life open', function () {
    $this->tracker->connect('Alice', at('2026-06-11T10:00:00Z'));
    $this->tracker->disconnect('Alice', at('2026-06-11T10:30:00Z'));

    $player = Player::where('gamertag', 'Alice')->first();
    expect($player->openSession())->toBeNull();
    expect($player->openLife())->not->toBeNull();        // disconnect does NOT end the life
    expect($player->openLife()->playtime_seconds)->toBe(1800);
});

it('accumulates playtime across multiple sessions in one life', function () {
    $this->tracker->connect('Alice', at('2026-06-11T10:00:00Z'));
    $this->tracker->disconnect('Alice', at('2026-06-11T10:30:00Z'));
    $this->tracker->connect('Alice', at('2026-06-11T11:00:00Z'));   // reuses the open life
    $this->tracker->disconnect('Alice', at('2026-06-11T11:15:00Z'));

    $player = Player::where('gamertag', 'Alice')->first();
    expect($player->lives()->count())->toBe(1);
    expect($player->openLife()->playtime_seconds)->toBe(1800 + 900);
});

it('ends the life on death and records cause and killer', function () {
    $this->tracker->connect('Alice', at('2026-06-11T10:00:00Z'));
    $this->tracker->death([
        'victim' => 'Alice', 'cause' => 'pvp', 'killer' => 'Bob',
    ], at('2026-06-11T10:20:00Z'));

    $player = App\Models\Player::where('gamertag', 'Alice')->first();
    expect($player->openLife())->toBeNull();             // life ended
    expect($player->openSession())->toBeNull();          // session closed
    $life = $player->lives()->latest('started_at')->first();
    expect($life->death_cause)->toBe('pvp');
    expect($life->death_by_gamertag)->toBe('Bob');
    expect($life->playtime_seconds)->toBe(1200);
    expect($life->ended_at->getTimestamp())->toBe(at('2026-06-11T10:20:00Z')->getTimestamp());
});

it('opens a new life on the next connect after a death', function () {
    $this->tracker->connect('Alice', at('2026-06-11T10:00:00Z'));
    $this->tracker->death(['victim' => 'Alice', 'cause' => 'died', 'killer' => null], at('2026-06-11T10:20:00Z'));
    $this->tracker->connect('Alice', at('2026-06-12T09:00:00Z'));

    $player = App\Models\Player::where('gamertag', 'Alice')->first();
    expect($player->lives()->count())->toBe(2);
    expect($player->openLife())->not->toBeNull();
});

it('ignores a duplicate death-log line for a life that already ended', function () {
    $this->tracker->connect('Alice', at('2026-06-11T10:00:00Z'));
    $this->tracker->death(['victim' => 'Alice', 'cause' => 'pvp', 'killer' => 'Bob'], at('2026-06-11T10:20:00Z'));
    // DayZ logs a second bare "(DEAD)" line for the same death moments later (no reconnect between).
    $this->tracker->death(['victim' => 'Alice', 'cause' => 'unknown', 'killer' => null], at('2026-06-11T10:20:23Z'));

    $alice = App\Models\Player::where('gamertag', 'Alice')->first();
    expect($alice->lives()->count())->toBe(1);                       // no spurious second life
    expect($alice->lives()->first()->death_cause)->toBe('pvp');      // original cause preserved
});

it('ignores a death for a player never seen connecting', function () {
    $this->tracker->death(['victim' => 'Ghost', 'cause' => 'drowned', 'killer' => null], at('2026-06-11T10:00:00Z'));
    expect(App\Models\Player::where('gamertag', 'Ghost')->exists())->toBeFalse();
});

it('closes all open sessions on reboot but keeps lives open', function () {
    $this->tracker->connect('Alice', at('2026-06-11T10:00:00Z'));
    $this->tracker->connect('Bob', at('2026-06-11T10:05:00Z'));
    $this->tracker->reboot(at('2026-06-11T10:30:00Z'));

    $alice = App\Models\Player::where('gamertag', 'Alice')->first();
    $bob = App\Models\Player::where('gamertag', 'Bob')->first();

    expect($alice->openSession())->toBeNull();
    expect($bob->openSession())->toBeNull();
    expect($alice->openLife())->not->toBeNull();         // still alive
    expect($alice->openLife()->playtime_seconds)->toBe(1800);
    expect($bob->openLife()->playtime_seconds)->toBe(1500);
});

it('continues the same life when a player reconnects after a reboot', function () {
    $this->tracker->connect('Alice', at('2026-06-11T10:00:00Z'));
    $this->tracker->reboot(at('2026-06-11T10:30:00Z'));
    $this->tracker->connect('Alice', at('2026-06-11T10:45:00Z'));

    $alice = App\Models\Player::where('gamertag', 'Alice')->first();
    expect($alice->lives()->count())->toBe(1);           // same life
    expect($alice->openSession())->not->toBeNull();
});

it('returns the closed session with its duration on disconnect', function () {
    $tracker = new App\Services\Life\LifeTracker();
    $tracker->connect('Alice', new DateTimeImmutable('2026-06-13T10:00:00Z'));

    $closed = $tracker->disconnect('Alice', new DateTimeImmutable('2026-06-13T10:30:00Z'));

    expect($closed)->toBeInstanceOf(App\Models\GameSession::class);
    expect($closed->duration_seconds)->toBe(1800);
    expect($closed->close_reason)->toBe('clean');
});

it('returns null when disconnecting a player with no open session', function () {
    $tracker = new App\Services\Life\LifeTracker();

    $closed = $tracker->disconnect('Ghost', new DateTimeImmutable('2026-06-13T10:00:00Z'));

    expect($closed)->toBeNull();
});

it('records weapon and distance for a pvp death', function () {
    $this->tracker->connect('Alice', at('2026-06-11T10:00:00Z'));
    $this->tracker->death([
        'victim' => 'Alice', 'cause' => 'pvp', 'killer' => 'Bob',
        'weapon' => 'SVD', 'distance' => 243.5,
    ], at('2026-06-11T10:20:00Z'));

    $life = App\Models\Player::where('gamertag', 'Alice')->first()->lives()->latest('started_at')->first();
    expect($life->death_weapon)->toBe('SVD');
    expect($life->death_distance)->toBe(243.5);
});

it('leaves weapon and distance null for a non-pvp death', function () {
    $this->tracker->connect('Carol', at('2026-06-11T10:00:00Z'));
    $this->tracker->death(['victim' => 'Carol', 'cause' => 'drowned', 'killer' => null], at('2026-06-11T10:20:00Z'));

    $life = App\Models\Player::where('gamertag', 'Carol')->first()->lives()->latest('started_at')->first();
    expect($life->death_weapon)->toBeNull();
    expect($life->death_distance)->toBeNull();
});
