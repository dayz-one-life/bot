<?php

use App\Models\BunkerVisit;
use App\Models\Life;
use App\Models\Player;
use App\Services\Bunker\BunkerVisitService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function bvLife(string $tag, string $start, ?string $end = null): array
{
    $player = Player::firstOrCreate(['gamertag' => $tag]);
    $life = Life::create([
        'player_id' => $player->id,
        'started_at' => CarbonImmutable::parse($start),
        'ended_at' => $end ? CarbonImmutable::parse($end) : null,
    ]);
    return [$player, $life];
}

it('records a visit and associates the life containing visited_at', function () {
    [$player, $life] = bvLife('Alice', '2026-06-14 01:00:00');

    $visit = (new BunkerVisitService())->record('Alice', new DateTimeImmutable('2026-06-14 02:30:35'));

    expect($visit)->not->toBeNull()
        ->and($visit->player_id)->toBe($player->id)
        ->and($visit->life_id)->toBe($life->id)
        ->and($visit->visited_at->format('Y-m-d H:i:s'))->toBe('2026-06-14 02:30:35');
});

it('skips a second visit inside the cooldown window', function () {
    bvLife('Alice', '2026-06-14 01:00:00');
    $svc = new BunkerVisitService();

    $svc->record('Alice', new DateTimeImmutable('2026-06-14 02:00:00'));
    $second = $svc->record('Alice', new DateTimeImmutable('2026-06-14 02:30:00')); // +30min, cooldown 60

    expect($second)->toBeNull()
        ->and(BunkerVisit::count())->toBe(1);
});

it('records again after the cooldown window', function () {
    bvLife('Alice', '2026-06-14 01:00:00');
    $svc = new BunkerVisitService();

    $svc->record('Alice', new DateTimeImmutable('2026-06-14 02:00:00'));
    $second = $svc->record('Alice', new DateTimeImmutable('2026-06-14 03:01:00')); // +61min

    expect($second)->not->toBeNull()
        ->and(BunkerVisit::count())->toBe(2);
});

it('records with null life when the player has no life containing the timestamp', function () {
    Player::create(['gamertag' => 'Ghost']); // no life rows

    $visit = (new BunkerVisitService())->record('Ghost', new DateTimeImmutable('2026-06-14 02:30:35'));

    expect($visit)->not->toBeNull()
        ->and($visit->life_id)->toBeNull();
});

it('associates the correct historical life when several exist', function () {
    [$player] = bvLife('Alice', '2026-06-10 00:00:00', '2026-06-11 00:00:00'); // old, ended
    [, $current] = bvLife('Alice', '2026-06-14 01:00:00');                      // open

    $visit = (new BunkerVisitService())->record('Alice', new DateTimeImmutable('2026-06-14 02:30:35'));

    expect($visit->life_id)->toBe($current->id);
});

it('returns null and writes nothing when tracking is disabled', function () {
    config(['bunker.enabled' => false]);

    $visit = (new BunkerVisitService())->record('Alice', new DateTimeImmutable('2026-06-14 02:00:00'));

    expect($visit)->toBeNull()
        ->and(BunkerVisit::count())->toBe(0);
});
