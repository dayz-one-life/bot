<?php

use App\Models\HitEvent;
use App\Services\Adm\AdmParser;
use App\Services\Adm\HitBackfillService;
use App\Services\Hit\HitEventService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('hits.enabled', true);
});

it('backfills hit lines from a file content into hit events', function () {
    $content = implode("\n", [
        'AdminLog started on 2026-06-14 at 02:00:00',
        '10:02:00 | Player "Victim" (id=V= pos=<1,2,3>)[HP: 50] hit by Player "Killer" (id=K=) into Torso',
        '10:03:00 | Player "Victim" (id=V= pos=<1,2,3>)[HP: 20] hit by Player "OtherKiller" (id=K2=) into Head',
    ]);

    $svc = new HitBackfillService(new AdmParser());
    $n = $svc->backfillFile($content, new DateTimeImmutable('2026-06-14 02:00:00'), 0, new HitEventService());

    expect($n)->toBe(2)
        ->and(HitEvent::count())->toBe(2);
});

it('records the correct victim and attacker gamertag', function () {
    $content = implode("\n", [
        'AdminLog started on 2026-06-14 at 02:00:00',
        '10:02:00 | Player "Victim" (id=V= pos=<1,2,3>)[HP: 50] hit by Player "Killer" (id=K=) into Torso',
    ]);

    $svc = new HitBackfillService(new AdmParser());
    $svc->backfillFile($content, new DateTimeImmutable('2026-06-14 02:00:00'), 0, new HitEventService());

    $event = HitEvent::first();
    expect($event->victim_gamertag)->toBe('Victim')
        ->and($event->attacker_gamertag)->toBe('Killer');
});

it('applies the clock offset when computing occurred_at', function () {
    $content = implode("\n", [
        'AdminLog started on 2026-06-14 at 02:00:00',
        '10:02:00 | Player "Victim" (id=V= pos=<1,2,3>)[HP: 50] hit by Player "Killer" (id=K=) into Torso',
    ]);

    $offsetMs = 4 * 3600 * 1000; // server is UTC-4: add 4h to get UTC
    $svc = new HitBackfillService(new AdmParser());
    $svc->backfillFile($content, new DateTimeImmutable('2026-06-14 02:00:00'), $offsetMs, new HitEventService());

    expect(HitEvent::first()->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-06-14 14:02:00');
});

it('skips non-hit lines and does not create events for them', function () {
    $content = implode("\n", [
        'AdminLog started on 2026-06-14 at 02:00:00',
        '10:02:00 | Player "Someone" (id=A=) connected',
        '10:02:30 | Player "Victim" (id=V= pos=<1,2,3>)[HP: 50] hit by Player "Killer" (id=K=) into Torso',
        '10:03:00 | Player "Someone" (id=A=) disconnected',
    ]);

    $svc = new HitBackfillService(new AdmParser());
    $n = $svc->backfillFile($content, new DateTimeImmutable('2026-06-14 02:00:00'), 0, new HitEventService());

    expect($n)->toBe(1)
        ->and(HitEvent::count())->toBe(1);
});
