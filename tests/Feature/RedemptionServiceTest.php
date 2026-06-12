<?php

use App\Models\Ban;
use App\Models\Player;
use App\Services\Ban\BanService;
use App\Services\Ban\NullBanNotifier;
use App\Services\Nitrado\NitradoClient;
use App\Services\Tokens\RedemptionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-06-12T12:00:00Z');
    Http::fake(['*/gameservers/settings' => function ($r) {
        if ($r->method() === 'POST') return Http::response(['status' => 'success', 'data' => []]);
        return Http::response(['status' => 'success', 'data' => ['settings' => ['general' => ['bans' => 'Target']]]]);
    }]);
    $bans = new BanService(new NitradoClient('t', 1), new NullBanNotifier());
    $this->svc = new RedemptionService($bans);
});
afterEach(fn () => CarbonImmutable::setTestNow());

function linkedWithTokens(string $tag, string $discordId, int $tokens): Player {
    return Player::create(['gamertag' => $tag, 'discord_user_id' => $discordId, 'unban_tokens' => $tokens, 'first_seen_at' => now(), 'last_seen_at' => now()]);
}
function tempBan(Player $p, string $expiresAt): void {
    Ban::create(['player_id' => $p->id, 'banned_at' => now(), 'expires_at' => $expiresAt, 'expired' => false, 'reason' => 'auto', 'source' => 'auto_death']);
}

it('spends a token to unban another temp-banned player', function () {
    $spender = linkedWithTokens('Spender', 'd-spend', 2);
    $target = linkedWithTokens('Target', 'd-target', 0);
    tempBan($target, '2026-06-12T20:00:00Z');

    $result = $this->svc->redeem('d-spend', 'Target');

    expect($result['status'])->toBe('unbanned');
    expect($spender->fresh()->unban_tokens)->toBe(1);
    expect($target->fresh()->used_tokens)->toBe(1);
    expect(Ban::where('expired', false)->count())->toBe(0);
});

it('defaults the target to the spender', function () {
    $me = linkedWithTokens('Me', 'd-me', 1);
    tempBan($me, '2026-06-12T20:00:00Z');
    expect($this->svc->redeem('d-me', null)['status'])->toBe('unbanned');
    expect($me->fresh()->unban_tokens)->toBe(0);
});

it('rejects when the spender has no tokens', function () {
    linkedWithTokens('Me', 'd-me', 0);
    tempBan(Player::where('gamertag', 'Me')->first(), '2026-06-12T20:00:00Z');
    expect($this->svc->redeem('d-me', null)['status'])->toBe('no_tokens');
});

it('rejects when the spender is not linked', function () {
    expect($this->svc->redeem('d-unknown', null)['status'])->toBe('not_linked');
});

it('rejects when the target has no active temporary ban', function () {
    linkedWithTokens('Me', 'd-me', 1);
    linkedWithTokens('Target', 'd-target', 0); // no ban
    expect($this->svc->redeem('d-me', 'Target')['status'])->toBe('no_active_ban');
});

it('rejects redeeming against a permanent ban and does not spend the token', function () {
    $me = linkedWithTokens('Me', 'd-me', 1);
    $target = linkedWithTokens('Target', 'd-target', 0);
    Ban::create(['player_id' => $target->id, 'banned_at' => now(), 'expires_at' => null, 'expired' => false, 'reason' => 'perma', 'source' => 'manual']);
    expect($this->svc->redeem('d-me', 'Target')['status'])->toBe('permanent_ban');
    expect($me->fresh()->unban_tokens)->toBe(1);
});
