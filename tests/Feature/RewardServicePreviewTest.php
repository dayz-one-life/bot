<?php

use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Services\State\BotState;
use App\Services\Tokens\RewardService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-07-01T00:30:00Z');
    $this->svc = new RewardService(new BotState());
});
afterEach(fn () => CarbonImmutable::setTestNow());

it('previews the grant without writing tokens or the month key', function () {
    $ref = Player::create(['gamertag' => 'Ref', 'discord_user_id' => 'd-ref', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $a = Player::create(['gamertag' => 'A', 'discord_user_id' => 'd-a', 'referrer_id' => $ref->id, 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $life = Life::create(['player_id' => $a->id, 'started_at' => '2026-06-10T00:00:00Z']);
    GameSession::create(['player_id' => $a->id, 'life_id' => $life->id, 'connected_at' => '2026-06-10T00:00:00Z']);

    $preview = $this->svc->previewGrant(CarbonImmutable::now());

    expect($preview['granted'])->toBe(3); // Ref: 1+1, A: 1
    expect(Player::where('gamertag', 'Ref')->first()->unban_tokens)->toBe(0); // no writes
    expect((new BotState())->get('last_reward_month'))->toBeNull();           // month key untouched
});
