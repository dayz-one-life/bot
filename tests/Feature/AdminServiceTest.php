<?php

use App\Models\Player;
use App\Services\Admin\AdminService;

beforeEach(fn () => $this->svc = new AdminService());

function seen(string $tag): Player {
    return Player::create(['gamertag' => $tag, 'first_seen_at' => now(), 'last_seen_at' => now()]);
}

it('force-links a discord user to a gamertag, clearing any prior links', function () {
    $old = seen('OldTag'); $old->update(['discord_user_id' => 'd-1']); // user d-1 currently on OldTag
    seen('NewTag');

    $r = $this->svc->forceLink('d-1', 'NewTag');

    expect($r['status'])->toBe('linked');
    expect(Player::where('gamertag', 'NewTag')->first()->discord_user_id)->toBe('d-1');
    expect(Player::where('gamertag', 'OldTag')->first()->discord_user_id)->toBeNull(); // prior link cleared
});

it('rejects force-link for an unknown gamertag', function () {
    expect($this->svc->forceLink('d-1', 'Ghost')['status'])->toBe('gamertag_not_found');
});

it('unlinks a discord user', function () {
    $p = seen('Tag'); $p->update(['discord_user_id' => 'd-1']);
    expect($this->svc->unlink('d-1')['status'])->toBe('unlinked');
    expect(Player::where('gamertag', 'Tag')->first()->discord_user_id)->toBeNull();
});

it('reports nothing to unlink for an unlinked user', function () {
    expect($this->svc->unlink('d-none')['status'])->toBe('not_linked');
});

it('grants tokens to a gamertag and clamps at zero', function () {
    $p = seen('Tag'); $p->update(['unban_tokens' => 2]);
    expect($this->svc->grantTokens('Tag', 3)['balance'])->toBe(5);
    expect($this->svc->grantTokens('Tag', -100)['balance'])->toBe(0); // clamp, no underflow
});

it('reports not found when granting to an unknown gamertag', function () {
    expect($this->svc->grantTokens('Ghost', 1)['status'])->toBe('gamertag_not_found');
});
