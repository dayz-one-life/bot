<?php

use App\Models\AdmFile;
use App\Models\Player;
use App\Services\Adm\AdmIngestor;
use App\Services\Adm\AdmParser;
use App\Services\Life\LifeTracker;
use App\Services\Nitrado\NitradoClient;
use App\Services\State\BotState;
use Illuminate\Support\Facades\Http;

it('applies events from a file in chronological order from the cursor', function () {
    $content = implode("\n", [
        'AdminLog started on 2026-06-11 at 09:00:00',
        '10:00:00 | Player "Alice" (id=A=) is connected',
        '10:20:00 | Player "Alice" (DEAD) (id=A=) killed by Player "Bob" (id=B=) with Knife',
        '10:25:00 | Player "Alice" (id=A=) has been disconnected',
    ]);

    $ingestor = new AdmIngestor(new AdmParser(), new LifeTracker());
    $fallback = new DateTimeImmutable('2026-06-11T00:00:00Z');

    // offsetMs = 0; cursor starts at 0; process all lines
    $newCursor = $ingestor->processFile($content, 0, $fallback, 0);
    expect($newCursor)->toBe(4);

    $alice = Player::where('gamertag', 'Alice')->first();
    $life = $alice->lives()->latest('started_at')->first();
    expect($life->death_cause)->toBe('pvp');
    expect($life->playtime_seconds)->toBe(1200); // 10:00 -> 10:20 death
});

it('does not reprocess lines before the cursor', function () {
    $content = implode("\n", [
        '10:00:00 | Player "Alice" (id=A=) is connected',
        '10:20:00 | Player "Alice" (id=A=) has been disconnected',
    ]);
    $ingestor = new AdmIngestor(new AdmParser(), new LifeTracker());
    $fallback = new DateTimeImmutable('2026-06-11T00:00:00Z');

    // cursor=1 -> skip the connect, only the disconnect line is "new"
    $ingestor->processFile($content, 1, $fallback, 0);
    $alice = Player::where('gamertag', 'Alice')->first();
    // no connect applied -> no open session existed -> disconnect is a no-op; no life created
    expect($alice?->lives()->count() ?? 0)->toBe(0);
});

it('backfills all files oldest-first and flips to live when caught up', function () {
    Http::fake([
        '*/gameservers' => Http::response(['status' => 'success', 'data' => ['gameserver' => [
            'game_specific' => ['path' => '/base/', 'log_files' => []],
        ]]]),
        '*/file_server/list*' => Http::response(['status' => 'success', 'data' => ['entries' => [
            ['name' => 'DayZServer_X1_x64_2026-06-10_00-00-00.ADM', 'path' => '/base/new.ADM', 'modified_at' => 1749513600],
            ['name' => 'DayZServer_X1_x64_2026-06-09_00-00-00.ADM', 'path' => '/base/old.ADM', 'modified_at' => 1749427200],
        ]]]),
        '*file=*old.ADM*' => Http::response(['status' => 'success', 'data' => ['token' => ['url' => 'https://dl/old']]]),
        '*file=*new.ADM*' => Http::response(['status' => 'success', 'data' => ['token' => ['url' => 'https://dl/new']]]),
        'https://dl/old' => Http::response("00:00:00 | Player \"Alice\" (id=A=) is connected\n00:30:00 | Player \"Alice\" (id=A=) has been disconnected"),
        'https://dl/new' => Http::response("01:00:00 | Player \"Alice\" (id=A=) is connected\n01:10:00 | Player \"Alice\" (id=A=) has been disconnected"),
    ]);

    $ingestor = new AdmIngestor(new AdmParser(), new LifeTracker());
    $client = new NitradoClient('t', 1);
    $state = new BotState();

    // budget large enough to drain both files in one tick
    $ingestor->tick($client, $state, backfillBudget: 10);

    expect(AdmFile::count())->toBe(2);
    expect(AdmFile::where('path', '/base/old.ADM')->first()->is_complete)->toBeTrue();
    expect($state->get('mode'))->toBe('live');     // caught up
    expect($state->get('go_live_at'))->not->toBeNull();

    $alice = Player::where('gamertag', 'Alice')->first();
    expect($alice->lives()->first()->playtime_seconds)->toBe(1800 + 600);
});

it('does not jump ahead to the newest file while older files are pending during backfill', function () {
    Http::fake([
        '*/gameservers' => Http::response(['status' => 'success', 'data' => ['gameserver' => [
            'game_specific' => ['path' => '/base/', 'log_files' => []],
        ]]]),
        '*/file_server/list*' => Http::response(['status' => 'success', 'data' => ['entries' => [
            ['name' => 'DayZServer_X1_x64_2026-06-09_00-00-00.ADM', 'path' => '/base/old.ADM', 'modified_at' => 1749427200],
            ['name' => 'DayZServer_X1_x64_2026-06-10_00-00-00.ADM', 'path' => '/base/mid.ADM', 'modified_at' => 1749513600],
            ['name' => 'DayZServer_X1_x64_2026-06-11_00-00-00.ADM', 'path' => '/base/new.ADM', 'modified_at' => 1749600000],
        ]]]),
        '*file=*old.ADM*' => Http::response(['status' => 'success', 'data' => ['token' => ['url' => 'https://dl/old']]]),
        '*file=*mid.ADM*' => Http::response(['status' => 'success', 'data' => ['token' => ['url' => 'https://dl/mid']]]),
        '*file=*new.ADM*' => Http::response(['status' => 'success', 'data' => ['token' => ['url' => 'https://dl/new']]]),
        'https://dl/old' => Http::response('00:00:00 | Player "Alice" (id=A=) is connected'),
        'https://dl/mid' => Http::response('00:00:00 | Player "Bob" (id=B=) is connected'),
        'https://dl/new' => Http::response('00:00:00 | Player "Carol" (id=C=) is connected'),
    ]);

    $ingestor = new AdmIngestor(new AdmParser(), new LifeTracker());
    $client = new NitradoClient('t', 1);
    $state = new BotState();

    // budget 1: only the single oldest file may drain this tick.
    $ingestor->tick($client, $state, backfillBudget: 1);

    expect(AdmFile::where('path', '/base/old.ADM')->first()?->is_complete)->toBeTrue(); // oldest drained
    expect(AdmFile::where('path', '/base/new.ADM')->first())->toBeNull();               // newest NOT jumped-ahead-to
    expect(Player::where('gamertag', 'Carol')->first())->toBeNull();                    // newest events not applied
    expect($state->get('mode', 'backfill'))->toBe('backfill');                          // not caught up -> no flip

    // Drain the remainder; newest is processed only after older files complete.
    $ingestor->tick($client, $state, backfillBudget: 10);

    expect($state->get('mode'))->toBe('live');                                          // now caught up
    expect(Player::where('gamertag', 'Carol')->first())->not->toBeNull();               // applied in order
});

it('captures a death-window log onto the life', function () {
    $content = implode("\n", [
        '00:00:00 | Player "Doomed" (id=D=) is connected',
        '00:01:00 | Player "Doomed" (id=D= pos=<1,2,3>)[HP: 30] hit by Player "Shooter" (id=S=) into Torso',
        '00:02:00 | Player "Doomed" (DEAD) (id=D=) killed by Player "Shooter" (id=S=) with M4A1 from 100.0 meters',
    ]);

    $ingestor = new App\Services\Adm\AdmIngestor(new App\Services\Adm\AdmParser(), new App\Services\Life\LifeTracker());
    $ingestor->processFile($content, 0, new DateTimeImmutable('2026-06-14T00:00:00Z'), 0);

    $life = App\Models\Life::whereNotNull('ended_at')->first();
    expect($life)->not->toBeNull();
    expect($life->death_log)->toContain('hit by Player "Shooter"');
    expect($life->death_log)->toContain('killed by Player "Shooter"');
    expect($life->death_log)->not->toContain('Shooter" (id=S=) is connected'); // only victim-mentioning lines
});
