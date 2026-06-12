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
    $this->deathBans = new DeathBanService($bans, $this->state, 12);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function endedLife(string $tag, string $endedAt, bool $banIssued = false): void {
    $p = Player::create(['gamertag' => $tag, 'first_seen_at' => now(), 'last_seen_at' => now()]);
    Life::create(['player_id' => $p->id, 'started_at' => $endedAt, 'ended_at' => $endedAt, 'death_cause' => 'pvp', 'ban_issued' => $banIssued]);
}

it('bans players whose lives ended after go_live and marks them', function () {
    endedLife('AfterGoLive', '2026-06-12T11:00:00Z');
    $n = $this->deathBans->run();

    expect($n)->toBe(1);
    expect(Ban::where('source', 'auto_death')->count())->toBe(1);
    expect(Life::where('death_cause', 'pvp')->first()->ban_issued)->toBeTrue();
});

it('does not ban deaths before go_live', function () {
    endedLife('BeforeGoLive', '2026-06-12T09:00:00Z');
    expect($this->deathBans->run())->toBe(0);
    expect(Ban::count())->toBe(0);
});

it('is idempotent — already-issued lives are skipped', function () {
    endedLife('Already', '2026-06-12T11:00:00Z', banIssued: true);
    expect($this->deathBans->run())->toBe(0);
});

it('does nothing before go_live is set', function () {
    $state = new BotState();
    $state->delete('go_live_at'); // clear key set by beforeEach — simulates pre-live state
    $bans = new BanService(new NitradoClient('t', 1), new NullBanNotifier());
    endedLife('Whoever', '2026-06-12T11:00:00Z');
    expect((new DeathBanService($bans, $state, 12))->run())->toBe(0);
    expect(Ban::count())->toBe(0);
});
