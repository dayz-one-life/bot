<?php

use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;
use App\Services\Stats\ReferralQueryService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-07-10T12:00:00Z'); // previous calendar month = June 2026
    $this->svc = new ReferralQueryService();
});
afterEach(fn () => CarbonImmutable::setTestNow());

function refPlayer(string $tag, string $discordId): Player {
    return Player::create(['gamertag' => $tag, 'discord_user_id' => $discordId, 'first_seen_at' => now(), 'last_seen_at' => now()]);
}
function connectedAt(Player $p, string $iso): void {
    $life = Life::create(['player_id' => $p->id, 'started_at' => $iso]);
    GameSession::create(['player_id' => $p->id, 'life_id' => $life->id, 'connected_at' => $iso]);
}

it('reports not linked for an unknown user', function () {
    expect($this->svc->forDiscordUser('d-none')['linked'])->toBeFalse();
});

it('lists referrals and counts those active in the previous month', function () {
    $me = refPlayer('Me', 'd-me');
    $a = refPlayer('A', 'd-a'); $a->update(['referrer_id' => $me->id]);
    $b = refPlayer('B', 'd-b'); $b->update(['referrer_id' => $me->id]);
    connectedAt($a, '2026-06-20T12:00:00Z'); // active in June
    connectedAt($b, '2026-05-01T12:00:00Z'); // not active in June

    $r = $this->svc->forDiscordUser('d-me');
    expect($r['linked'])->toBeTrue();
    expect($r['referrals'])->toHaveCount(2);
    expect($r['activeCount'])->toBe(1);
    $byTag = collect($r['referrals'])->keyBy('gamertag');
    expect($byTag['A']['active'])->toBeTrue();
    expect($byTag['B']['active'])->toBeFalse();
});

it('reports not found for an unknown gamertag', function () {
    expect($this->svc->forGamertag('Nobody')['found'])->toBeFalse();
});

it('lists a gamertag\'s referrals and counts those active in the previous month', function () {
    $me = refPlayer('Me', 'd-me');
    $a = refPlayer('A', 'd-a'); $a->update(['referrer_id' => $me->id]);
    $b = refPlayer('B', 'd-b'); $b->update(['referrer_id' => $me->id]);
    connectedAt($a, '2026-06-20T12:00:00Z'); // active in June
    connectedAt($b, '2026-05-01T12:00:00Z'); // not active in June

    $r = $this->svc->forGamertag('Me');
    expect($r['found'])->toBeTrue();
    expect($r['referrals'])->toHaveCount(2);
    expect($r['activeCount'])->toBe(1);
    $byTag = collect($r['referrals'])->keyBy('gamertag');
    expect($byTag['A']['active'])->toBeTrue();
    expect($byTag['B']['active'])->toBeFalse();
});
