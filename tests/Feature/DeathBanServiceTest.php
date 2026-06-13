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

class RecordingDeathFeed implements App\Services\DeathFeed\DeathFeedNotifier {
    public array $posts = [];
    public function died(App\Models\Life $life, App\Models\Ban $ban): void {
        $this->posts[] = ['life' => $life->id, 'cause' => $life->death_cause, 'expires' => $ban->expires_at?->toIso8601String()];
    }
}

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

it('posts to the death feed for a fresh death, even in dry run', function () {
    endedLife('Fresh', '2026-06-12T11:55:00Z'); // 5 min before test-now 12:00
    $feed = new RecordingDeathFeed();
    $bans = new BanService(new NitradoClient('t', 1), new NullBanNotifier(), dryRun: true);
    $service = new DeathBanService($bans, $this->state, 12, $feed, 10);

    $service->run();

    expect($feed->posts)->toHaveCount(1);
    expect($feed->posts[0]['cause'])->toBe('pvp');
    expect($feed->posts[0]['expires'])->toBe(CarbonImmutable::now()->addHours(12)->toIso8601String());
});

it('does not post to the feed for a stale death but still bans it', function () {
    endedLife('Stale', '2026-06-12T11:00:00Z'); // 60 min before test-now, window is 10
    $feed = new RecordingDeathFeed();
    $bans = new BanService(new NitradoClient('t', 1), new NullBanNotifier());
    $service = new DeathBanService($bans, $this->state, 12, $feed, 10);

    $n = $service->run();

    expect($n)->toBe(1);                                  // still banned
    expect($feed->posts)->toBeEmpty();                    // but not posted
});

it('does not double-post across ticks', function () {
    endedLife('Once', '2026-06-12T11:55:00Z');
    $feed = new RecordingDeathFeed();
    $bans = new BanService(new NitradoClient('t', 1), new NullBanNotifier());
    $service = new DeathBanService($bans, $this->state, 12, $feed, 10);

    $service->run();
    $service->run(); // second tick: ban_issued already true, nothing reselected

    expect($feed->posts)->toHaveCount(1);
});

it('does nothing before go_live is set', function () {
    $state = new BotState();
    $state->delete('go_live_at'); // clear key set by beforeEach — simulates pre-live state
    $bans = new BanService(new NitradoClient('t', 1), new NullBanNotifier());
    endedLife('Whoever', '2026-06-12T11:00:00Z');
    expect((new DeathBanService($bans, $state, 12))->run())->toBe(0);
    expect(Ban::count())->toBe(0);
});
