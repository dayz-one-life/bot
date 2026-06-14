<?php

use App\Models\BunkerVisit;
use App\Models\Life;
use App\Models\Player;
use App\Services\Adm\AdmParser;
use App\Services\Adm\BunkerVisitBackfillService;
use App\Services\Bunker\BunkerVisitService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('backfills entrance lines from a file content into visits', function () {
    $player = Player::create(['gamertag' => 'RonaldRaygun552']);
    Life::create(['player_id' => $player->id, 'started_at' => CarbonImmutable::parse('2026-06-14 02:00:00')]);

    $content = implode("\n", [
        'AdminLog started on 2026-06-14 at 02:00:00',
        '02:30:35 | Player "RonaldRaygun552" (id=89B90470 pos=<5154.0, 1075.1, 56.3>) was teleported from: <4767,339,10376> to: <5154,56,1075>. Reason: Spawning in Player Restricted Area: RestrictedAreaBunkerEntrance',
        '03:01:32 | Player "RonaldRaygun552" (id=89B90470 pos=<4828.4, 10291.8, 339.9>) was teleported from: <5005,17,1086> to: <4828,339,10291>. Reason: Spawning in Player Restricted Area: RestrictedAreaBunkerExit',
    ]);

    $svc = new BunkerVisitBackfillService(new AdmParser());
    $n = $svc->backfillFile($content, new DateTimeImmutable('2026-06-14 02:00:00'), 0, new BunkerVisitService());

    expect($n)->toBe(1) // only the entrance, not the exit
        ->and(BunkerVisit::count())->toBe(1);
});

it('is idempotent on re-run via the cooldown window', function () {
    $player = Player::create(['gamertag' => 'RonaldRaygun552']);
    Life::create(['player_id' => $player->id, 'started_at' => CarbonImmutable::parse('2026-06-14 02:00:00')]);

    $content = implode("\n", [
        'AdminLog started on 2026-06-14 at 02:00:00',
        '02:30:35 | Player "RonaldRaygun552" (id=89B90470 pos=<5154,1075,56>) was teleported from: <0,0,0> to: <0,0,0>. Reason: Spawning in Player Restricted Area: RestrictedAreaBunkerEntrance',
    ]);

    $svc = new BunkerVisitBackfillService(new AdmParser());
    $visits = new BunkerVisitService();
    $svc->backfillFile($content, new DateTimeImmutable('2026-06-14 02:00:00'), 0, $visits);
    $svc->backfillFile($content, new DateTimeImmutable('2026-06-14 02:00:00'), 0, $visits);

    expect(BunkerVisit::count())->toBe(1);
});

it('applies the clock offset when computing visited_at', function () {
    $content = implode("\n", [
        'AdminLog started on 2026-06-14 at 02:00:00',
        '02:30:35 | Player "RonaldRaygun552" (id=89B90470 pos=<5154.0, 1075.1, 56.3>) was teleported from: <0,0,0> to: <0,0,0>. Reason: Spawning in Player Restricted Area: RestrictedAreaBunkerEntrance',
    ]);

    $offsetMs = 4 * 3600 * 1000; // server is UTC-4: add 4h to get UTC
    $svc = new BunkerVisitBackfillService(new AdmParser());
    $svc->backfillFile($content, new DateTimeImmutable('2026-06-14 02:00:00'), $offsetMs, new BunkerVisitService());

    expect(BunkerVisit::first()->visited_at->format('Y-m-d H:i:s'))->toBe('2026-06-14 06:30:35');
});
