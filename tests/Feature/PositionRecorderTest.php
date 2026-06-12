<?php

use App\Models\Player;
use App\Models\PlayerPosition;
use App\Services\Adm\PositionRecorder;

it('records a position for a known player', function () {
    $p = Player::create(['gamertag' => 'Alice', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    (new PositionRecorder())->record('Alice', 10.0, 20.0, new DateTimeImmutable('2026-06-12T12:00:00Z'));
    expect(PlayerPosition::where('player_id', $p->id)->count())->toBe(1);
});

it('ignores positions for an unknown player', function () {
    (new PositionRecorder())->record('Ghost', 1.0, 2.0, new DateTimeImmutable('2026-06-12T12:00:00Z'));
    expect(PlayerPosition::count())->toBe(0);
});

it('harvests a position from a connect line during ingest', function () {
    $ingestor = new App\Services\Adm\AdmIngestor(new App\Services\Adm\AdmParser(), new App\Services\Life\LifeTracker());
    $content = "AdminLog started on 2026-06-12 at 00:00:00\n"
        ."12:00:00 | Player \"Alice\" (id=ABC= pos=<500.0, 600.0, 5.0>) is connected\n";
    $ingestor->processFile($content, 0, new DateTimeImmutable('2026-06-12T00:00:00Z'), 0);
    expect(PlayerPosition::count())->toBe(1);
    expect((float) PlayerPosition::first()->x)->toBe(500.0);
});
