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

/** A BanNotifier test double that records which gamertags it was asked to announce. */
function recordingNotifier(): \App\Services\Ban\BanNotifier {
    return new class implements \App\Services\Ban\BanNotifier {
        public array $banned = [];
        public array $unbanned = [];
        public function banned(\App\Models\Ban $ban, \App\Models\Player $player, bool $isExtension): void { $this->banned[] = $player->gamertag; }
        public function unbanned(\App\Models\Player $player, string $reason, ?string $originalReason): void { $this->unbanned[] = $player->gamertag; }
    };
}

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

it('skips the Nitrado write on unban in dry-run mode but still expires DB bans', function () {
    $this->service->ban('Alice', 12, 'auto', 'auto_death'); // create an active ban
    Http::fake();                                            // reset recorded requests
    $dry = new BanService(new NitradoClient('t', 1), new NullBanNotifier(), dryRun: true);

    $dry->unban('Alice', 'cleanup');

    expect(App\Models\Ban::where('expired', false)->count())->toBe(0);
    Http::assertNothingSent();
});

it('does NOT notify when banning in dry-run mode', function () {
    $spy = recordingNotifier();
    $dry = new BanService(new NitradoClient('t', 1), $spy, dryRun: true);

    $dry->ban('Alice', 12, 'auto', 'auto_death');

    expect($spy->banned)->toBe([]);
});

it('notifies when banning in live mode', function () {
    $spy = recordingNotifier();
    $live = new BanService(new NitradoClient('t', 1), $spy);

    $live->ban('Alice', 12, 'auto', 'auto_death');

    expect($spy->banned)->toBe(['Alice']);
});

it('forces the Nitrado removal and notifies on a manual unban even in dry-run mode', function () {
    $this->service->ban('Alice', 12, 'auto', 'auto_death'); // active ban in DB + Nitrado
    Http::fake([
        '*/gameservers/settings' => function ($r) {
            if ($r->method() === 'POST') return Http::response(['status' => 'success', 'data' => []]);
            return Http::response(['status' => 'success', 'data' => ['settings' => ['general' => ['bans' => 'Alice']]]]);
        },
    ]);
    $spy = recordingNotifier();
    $dry = new BanService(new NitradoClient('t', 1), $spy, dryRun: true);

    $dry->unban('Alice', 'Manual unban', force: true);

    expect(App\Models\Ban::where('expired', false)->count())->toBe(0);
    expect($spy->unbanned)->toBe(['Alice']);
    Http::assertSent(fn ($r) => $r->method() === 'POST' && ! str_contains($r['value'], 'Alice'));
});

it('does NOT notify on an automated (non-forced) unban in dry-run mode', function () {
    $this->service->ban('Alice', 12, 'auto', 'auto_death'); // create an active ban
    Http::fake();                                            // reset recorded requests
    $spy = recordingNotifier();
    $dry = new BanService(new NitradoClient('t', 1), $spy, dryRun: true);

    $dry->unban('Alice', 'Ban expired'); // no force

    expect(App\Models\Ban::where('expired', false)->count())->toBe(0);
    expect($spy->unbanned)->toBe([]);
    Http::assertNothingSent();
});
