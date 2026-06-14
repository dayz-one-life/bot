<?php

use App\Models\BunkerVisit;
use App\Models\Life;
use App\Models\Player;
use App\Services\Adm\AdmIngestor;
use App\Services\Adm\AdmParser;
use App\Services\Life\LifeTracker;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records a bunker visit while ingesting an entrance line', function () {
    // Pre-existing open life (player logged out inside the bunker earlier).
    $player = Player::create(['gamertag' => 'RonaldRaygun552']);
    $life = Life::create(['player_id' => $player->id, 'started_at' => CarbonImmutable::parse('2026-06-14 00:00:00')]);

    $content = implode("\n", [
        'AdminLog started on 2026-06-14 at 02:00:00',
        '02:30:35 | Player "RonaldRaygun552" (id=89B90470 pos=<5154.0, 1075.1, 56.3>) was teleported from: <4767.4, 339.4, 10376.3> to: <5154.0, 56.3, 1075.1>. Reason: Spawning in Player Restricted Area: RestrictedAreaBunkerEntrance',
        '02:30:35 | Player "RonaldRaygun552" (id=89B90470 pos=<5154.1, 1075.1, 56.4>) is connected',
    ]);

    $ingestor = new AdmIngestor(new AdmParser(), new LifeTracker());
    $ingestor->processFile($content, 0, new DateTimeImmutable('2026-06-14 00:00:00'), 0);

    expect(BunkerVisit::count())->toBe(1)
        ->and(BunkerVisit::first()->life_id)->toBe($life->id);
});
