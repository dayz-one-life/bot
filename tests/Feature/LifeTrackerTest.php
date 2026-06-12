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
