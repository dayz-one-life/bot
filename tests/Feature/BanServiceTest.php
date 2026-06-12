<?php

use App\Models\Ban;
use App\Models\Player;
use App\Services\Ban\BanService;
use App\Services\Ban\NullBanNotifier;
use App\Services\Nitrado\NitradoClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-06-12T12:00:00Z');
    Http::fake([
        '*/gameservers/settings' => function ($r) {
            if ($r->method() === 'POST') return Http::response(['status' => 'success', 'data' => []]);
            return Http::response(['status' => 'success', 'data' => ['settings' => ['general' => ['bans' => '']]]]);
        },
    ]);
    $this->service = new BanService(new NitradoClient('t', 1), new NullBanNotifier());
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('creates a 12h ban and applies it to Nitrado', function () {
    $ban = $this->service->ban('Alice', 12, 'One life autoban', 'auto_death');

    expect($ban->expires_at->equalTo(CarbonImmutable::now()->addHours(12)))->toBeTrue();
    expect(Ban::count())->toBe(1);
    expect(Ban::first()->source)->toBe('auto_death');
    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r['value'], 'Alice'));
});

it('extends an existing active ban instead of stacking', function () {
    $this->service->ban('Alice', 12, 'first', 'auto_death');
    CarbonImmutable::setTestNow('2026-06-12T18:00:00Z');
    $this->service->ban('Alice', 12, 'second', 'auto_death');

    expect(Ban::count())->toBe(1);
    expect(Ban::first()->reason)->toBe('second');
    expect(Ban::first()->expires_at->equalTo(CarbonImmutable::now()->addHours(12)))->toBeTrue();
});

it('creates a permanent ban when hours is 0', function () {
    $ban = $this->service->ban('Alice', 0, 'perma', 'manual');
    expect($ban->expires_at)->toBeNull();
});

it('skips the Nitrado write in dry-run mode', function () {
    $dry = new BanService(new NitradoClient('t', 1), new NullBanNotifier(), dryRun: true);
    $dry->ban('Bob', 12, 'auto', 'auto_death');
    expect(Ban::whereHas('player', fn ($q) => $q->where('gamertag', 'Bob'))->count())->toBe(1);
    Http::assertNotSent(fn ($r) => $r->method() === 'POST');
});

it('unbans: removes from Nitrado and expires active DB bans', function () {
    $this->service->ban('Alice', 12, 'auto', 'auto_death');
    Http::fake([
        '*/gameservers/settings' => function ($r) {
            if ($r->method() === 'POST') return Http::response(['status' => 'success', 'data' => []]);
            return Http::response(['status' => 'success', 'data' => ['settings' => ['general' => ['bans' => 'Alice']]]]);
        },
    ]);

    $this->service->unban('Alice', 'Ban expired');

    expect(App\Models\Ban::where('expired', false)->count())->toBe(0);
    Http::assertSent(fn ($r) => $r->method() === 'POST' && ! str_contains($r['value'], 'Alice'));
});

it('unban is a no-op on the DB for an unknown gamertag', function () {
    $this->service->unban('Ghost', 'cleanup');
    expect(App\Models\Player::where('gamertag', 'Ghost')->exists())->toBeFalse();
});
