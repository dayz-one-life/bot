<?php

use App\Models\Player;
use App\Models\PlayerPosition;
use App\Services\Adm\AdmParser;
use App\Services\Adm\PositionBackfillService;

beforeEach(function () {
    $this->svc = new PositionBackfillService(new AdmParser());
});

function backfillContent(): string {
    return "AdminLog started on 2026-06-12 at 00:00:00\n"
        ."12:00:00 | Player \"Alice\" (id=AAA pos=<100.5, 200.5, 5.0>) is connected\n"
        ."12:05:00 | Player \"Bob\" (id=BBB pos=<300.0, 400.0, 6.0>)\n"
        ."12:06:00 | Player \"Ghost\" (id=GGG pos=<1.0, 2.0, 0.0>)\n" // not in map -> skipped
        ."12:07:00 | Player \"Victim\" (id=VVV pos=<9.0, 9.0, 1.0>)[HP: 50] hit by Player \"Alice\" (id=AAA pos=<8.0,8.0,1.0>) into Torso\n"; // hit-by -> skipped
}

it('extracts only mapped, non-hit-by positions with correct UTC timestamps', function () {
    $alice = Player::create(['gamertag' => 'Alice', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $bob = Player::create(['gamertag' => 'Bob', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $map = ['Alice' => $alice->id, 'Bob' => $bob->id];

    $rows = $this->svc->extractPositions(backfillContent(), new DateTimeImmutable('2026-06-12T00:00:00Z'), 0, $map);

    expect($rows)->toHaveCount(2); // Ghost (unmapped) + the hit-by line excluded
    expect($rows[0]['player_id'])->toBe($alice->id);
    expect($rows[0]['x'])->toBe(100.5);
    expect($rows[0]['y'])->toBe(200.5);
    // offsetMs=0, log time 12:00:00 on 2026-06-12 UTC
    expect($rows[0]['recorded_at'])->toBe('2026-06-12 12:00:00');
    expect($rows[1]['player_id'])->toBe($bob->id);
    expect($rows[1]['recorded_at'])->toBe('2026-06-12 12:05:00');
});

it('applies the clock offset to produce UTC timestamps', function () {
    $alice = Player::create(['gamertag' => 'Alice', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    // +1h offset (3600000 ms): 12:00:00 local -> 13:00:00 UTC
    $rows = $this->svc->extractPositions(backfillContent(), new DateTimeImmutable('2026-06-12T00:00:00Z'), 3600000, ['Alice' => $alice->id]);
    expect($rows[0]['recorded_at'])->toBe('2026-06-12 13:00:00');
});

it('bulk-inserts extracted rows into player_positions', function () {
    $alice = Player::create(['gamertag' => 'Alice', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $rows = [
        ['player_id' => $alice->id, 'x' => 1.0, 'y' => 2.0, 'recorded_at' => '2026-06-12 12:00:00'],
        ['player_id' => $alice->id, 'x' => 3.0, 'y' => 4.0, 'recorded_at' => '2026-06-12 12:05:00'],
    ];
    $this->svc->insertRows($rows);
    expect(PlayerPosition::count())->toBe(2);
    expect((float) PlayerPosition::orderBy('recorded_at')->first()->x)->toBe(1.0);
});
