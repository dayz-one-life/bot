<?php

use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Services\State\BotState;
use App\Services\Tokens\RewardService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-07-01T00:30:00Z'); // early on the 1st; "previous month" = June 2026
    $this->state = new BotState();
    $this->svc = new RewardService($this->state);
});
afterEach(fn () => CarbonImmutable::setTestNow());

function linkedPlayer(string $tag, string $discordId): Player {
    return Player::create(['gamertag' => $tag, 'discord_user_id' => $discordId, 'first_seen_at' => now(), 'last_seen_at' => now()]);
}
function connectAt(Player $p, string $iso): void {
    $life = Life::create(['player_id' => $p->id, 'started_at' => $iso]);
    GameSession::create(['player_id' => $p->id, 'life_id' => $life->id, 'connected_at' => $iso]);
}

it('grants +1 base to each linked player', function () {
    linkedPlayer('A', 'd-a');
    linkedPlayer('B', 'd-b');
    $result = $this->svc->monthlyGrant(CarbonImmutable::now());

    expect($result['granted'])->toBe(2);
    expect(Player::where('gamertag', 'A')->first()->unban_tokens)->toBe(1);
});

it('adds +1 per referred player active in the previous month', function () {
    $referrer = linkedPlayer('Ref', 'd-ref');
    $active = linkedPlayer('Active', 'd-active'); $active->update(['referrer_id' => $referrer->id]);
    $inactive = linkedPlayer('Inactive', 'd-inactive'); $inactive->update(['referrer_id' => $referrer->id]);
    connectAt($active, '2026-06-15T12:00:00Z');     // active in June
    connectAt($inactive, '2026-05-10T12:00:00Z');   // last active in May, not June

    $this->svc->monthlyGrant(CarbonImmutable::now());

    expect(Player::where('gamertag', 'Ref')->first()->unban_tokens)->toBe(2);     // 1 base + 1 active referral
    expect(Player::where('gamertag', 'Active')->first()->unban_tokens)->toBe(1);  // 1 base
});

it('is idempotent within the same month', function () {
    linkedPlayer('A', 'd-a');
    $this->svc->monthlyGrant(CarbonImmutable::now());
    $second = $this->svc->monthlyGrant(CarbonImmutable::now());
    expect($second['granted'])->toBe(0);
    expect(Player::where('gamertag', 'A')->first()->unban_tokens)->toBe(1);
});

it('does not grant to unlinked players', function () {
    Player::create(['gamertag' => 'Unlinked', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $this->svc->monthlyGrant(CarbonImmutable::now());
    expect(Player::where('gamertag', 'Unlinked')->first()->unban_tokens)->toBe(0);
});
