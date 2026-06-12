<?php

use App\Models\Player;
use App\Services\Tokens\ReferrerService;

beforeEach(fn () => $this->svc = new ReferrerService());

function linked(string $tag, string $discordId): Player {
    return Player::create(['gamertag' => $tag, 'discord_user_id' => $discordId, 'first_seen_at' => now(), 'last_seen_at' => now()]);
}

it('sets a referrer when none exists', function () {
    $me = linked('Me', 'd-me');
    $ref = linked('Ref', 'd-ref');
    expect($this->svc->setReferrer('d-me', 'Ref')['status'])->toBe('set');
    expect($me->fresh()->referrer_id)->toBe($ref->id);
});

it('rejects when caller is not linked', function () {
    linked('Ref', 'd-ref');
    expect($this->svc->setReferrer('d-unknown', 'Ref')['status'])->toBe('not_linked');
});

it('rejects when a referrer is already set (locked)', function () {
    $me = linked('Me', 'd-me');
    $r1 = linked('R1', 'd-r1'); linked('R2', 'd-r2');
    $me->update(['referrer_id' => $r1->id]);
    expect($this->svc->setReferrer('d-me', 'R2')['status'])->toBe('already_set');
    expect($me->fresh()->referrer_id)->toBe($r1->id);
});

it('rejects self-referral and an unlinked/unknown referrer', function () {
    linked('Me', 'd-me');
    expect($this->svc->setReferrer('d-me', 'Me')['status'])->toBe('invalid_referrer');
    expect($this->svc->setReferrer('d-me', 'Ghost')['status'])->toBe('invalid_referrer');
});
