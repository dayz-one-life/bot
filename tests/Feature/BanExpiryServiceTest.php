<?php

use App\Models\Ban;
use App\Models\Player;
use App\Services\Ban\BanService;
use App\Services\Ban\NullBanNotifier;
use App\Services\BanExpiryService;
use App\Services\Nitrado\NitradoClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-06-12T12:00:00Z');
    $this->postedValues = [];
    Http::fake(['*/gameservers/settings' => function ($r) {
        if ($r->method() === 'POST') { $this->postedValues[] = $r['value']; return Http::response(['status' => 'success', 'data' => []]); }
        return Http::response(['status' => 'success', 'data' => ['settings' => ['general' => ['bans' => 'Stale']]]]);
    }]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function makeBan(string $tag, ?string $expiresAt, bool $expired = false): void {
    $p = Player::create(['gamertag' => $tag, 'first_seen_at' => now(), 'last_seen_at' => now()]);
    Ban::create(['player_id' => $p->id, 'banned_at' => now()->subHours(13), 'expires_at' => $expiresAt, 'expired' => $expired, 'reason' => 'auto', 'source' => 'auto_death']);
}

it('lifts bans whose expires_at has passed', function () {
    makeBan('Expired', '2026-06-12T11:00:00Z');   // expired 1h ago
    makeBan('Active', '2026-06-12T20:00:00Z');     // still active

    $svc = new BanExpiryService();
    $svc->sweep(new BanService(new NitradoClient('t', 1), new NullBanNotifier()), new NitradoClient('t', 1));

    expect(Ban::where('expired', false)->count())->toBe(1);
    expect(Ban::where('expired', true)->count())->toBe(1);
});

it('reconciles: active DB bans missing from Nitrado are re-added', function () {
    makeBan('Active', '2026-06-12T20:00:00Z');  // active; Nitrado fake returns only "Stale"

    $svc = new BanExpiryService();
    $svc->sweep(new BanService(new NitradoClient('t', 1), new NullBanNotifier()), new NitradoClient('t', 1));

    expect(collect($this->postedValues)->contains(fn ($v) => str_contains($v, 'Active')))->toBeTrue();
});

it('does NOT push active DB bans to Nitrado during reconcile in dry-run mode', function () {
    makeBan('Active', '2026-06-12T20:00:00Z');  // active; Nitrado fake returns only "Stale"

    $svc = new BanExpiryService();
    $svc->sweep(new BanService(new NitradoClient('t', 1), new NullBanNotifier(), dryRun: true), new NitradoClient('t', 1));

    expect(collect($this->postedValues)->contains(fn ($v) => str_contains($v, 'Active')))->toBeFalse();
});
