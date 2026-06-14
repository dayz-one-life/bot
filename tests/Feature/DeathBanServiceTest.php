<?php

use App\Models\Ban;
use App\Models\Life;
use App\Models\Player;
use App\Services\Ban\BanService;
use App\Services\Ban\DeathBanService;
use App\Services\Ban\NullBanNotifier;
use App\Services\Nitrado\NitradoClient;
use App\Services\State\BotState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-06-12T12:00:00Z');
    Http::fake(['*/gameservers/settings' => function ($r) {
        if ($r->method() === 'POST') return Http::response(['status' => 'success', 'data' => []]);
        return Http::response(['status' => 'success', 'data' => ['settings' => ['general' => ['bans' => '']]]]);
    }]);
    $this->state = new BotState();
    $this->state->set('go_live_at', '2026-06-12T10:00:00+00:00');
    $bans = new BanService(new NitradoClient('t', 1), new NullBanNotifier());
    // banHours 12, banMinPlaytime 3600s (60 min).
    $this->deathBans = new DeathBanService($bans, $this->state, 12, 3600);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function endedLife(string $tag, string $endedAt, int $playtime = 7200, bool $banIssued = false): void {
    $p = Player::create(['gamertag' => $tag, 'first_seen_at' => now(), 'last_seen_at' => now()]);
    Life::create([
        'player_id' => $p->id, 'started_at' => $endedAt, 'ended_at' => $endedAt,
        'death_cause' => 'pvp', 'playtime_seconds' => $playtime, 'ban_issued' => $banIssued,
    ]);
}

it('bans a death with >= 60 min playtime after go_live', function () {
    endedLife('Veteran', '2026-06-12T11:00:00Z', playtime: 3600);
    expect($this->deathBans->run())->toBe(1);
    expect(Ban::where('source', 'auto_death')->count())->toBe(1);
    expect(Life::first()->ban_issued)->toBeTrue();
});

it('does NOT ban a death under 60 min playtime', function () {
    endedLife('Rookie', '2026-06-12T11:00:00Z', playtime: 3599);
    expect($this->deathBans->run())->toBe(0);
    expect(Ban::count())->toBe(0);
    // The life is left unmarked so it is not silently considered "handled".
    expect(Life::first()->ban_issued)->toBeFalse();
});

it('does not ban deaths before go_live even with enough playtime', function () {
    endedLife('Old', '2026-06-12T09:00:00Z', playtime: 7200);
    expect($this->deathBans->run())->toBe(0);
    expect(Ban::count())->toBe(0);
});

it('is idempotent — already-issued lives are skipped', function () {
    endedLife('Already', '2026-06-12T11:00:00Z', playtime: 7200, banIssued: true);
    expect($this->deathBans->run())->toBe(0);
});

it('does nothing before go_live is set', function () {
    $state = new BotState();
    $state->delete('go_live_at');
    $bans = new BanService(new NitradoClient('t', 1), new NullBanNotifier());
    endedLife('Whoever', '2026-06-12T11:00:00Z', playtime: 7200);
    expect((new DeathBanService($bans, $state, 12, 3600))->run())->toBe(0);
    expect(Ban::count())->toBe(0);
});
