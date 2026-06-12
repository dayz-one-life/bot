<?php

use App\Models\Player;
use App\Services\Tokens\LinkService;

beforeEach(fn () => $this->link = new LinkService());

function seenPlayer(string $tag): Player {
    return Player::create(['gamertag' => $tag, 'first_seen_at' => now(), 'last_seen_at' => now()]);
}

it('links a seen gamertag and grants one token', function () {
    seenPlayer('Alice');
    $result = $this->link->link('discord-1', 'Alice', null);

    expect($result['status'])->toBe('linked');
    $alice = Player::where('gamertag', 'Alice')->first();
    expect($alice->discord_user_id)->toBe('discord-1');
    expect($alice->unban_tokens)->toBe(1);
    expect($alice->link_rewarded)->toBeTrue();
});

it('rejects linking a gamertag never seen in the logs', function () {
    expect($this->link->link('discord-1', 'Nobody', null)['status'])->toBe('gamertag_not_found');
    expect(Player::where('gamertag', 'Nobody')->exists())->toBeFalse();
});

it('rejects when the discord user is already linked', function () {
    seenPlayer('Alice'); seenPlayer('Alt');
    $this->link->link('discord-1', 'Alice', null);
    $result = $this->link->link('discord-1', 'Alt', null);
    expect($result['status'])->toBe('already_linked');
    expect(Player::where('gamertag', 'Alt')->first()->discord_user_id)->toBeNull();
});

it('rejects when the gamertag is already taken by someone else', function () {
    $alice = seenPlayer('Alice');
    $alice->update(['discord_user_id' => 'discord-1']);
    expect($this->link->link('discord-2', 'Alice', null)['status'])->toBe('gamertag_not_found');
});

it('sets a valid referrer at link time', function () {
    $ref = seenPlayer('Ref'); $ref->update(['discord_user_id' => 'discord-ref']);
    seenPlayer('Alice');
    $result = $this->link->link('discord-1', 'Alice', 'Ref');
    expect($result['status'])->toBe('linked');
    expect($result['referrer'])->toBe('Ref');
    expect(Player::where('gamertag', 'Alice')->first()->referrer_id)->toBe($ref->id);
});

it('rejects self-referral and unlinked referrer', function () {
    seenPlayer('Alice');
    expect($this->link->link('discord-1', 'Alice', 'Alice')['status'])->toBe('invalid_referrer');

    seenPlayer('Bob'); seenPlayer('Carol');
    expect($this->link->link('discord-2', 'Bob', 'Carol')['status'])->toBe('invalid_referrer');
});

it('does not re-grant a token on a second link attempt by an already-linked user', function () {
    seenPlayer('Alice');
    $this->link->link('discord-1', 'Alice', null);
    $this->link->link('discord-1', 'Alice', null);
    expect(Player::where('gamertag', 'Alice')->first()->unban_tokens)->toBe(1);
});

it('does not grant a token when link_rewarded is already true (anti-farm guard)', function () {
    // A previously-rewarded gamertag that was unlinked keeps link_rewarded=true,
    // so re-linking it grants no new token.
    $p = seenPlayer('Alice');
    $p->update(['link_rewarded' => true]);

    $result = $this->link->link('discord-1', 'Alice', null);

    expect($result['status'])->toBe('linked');
    expect($result['tokenGranted'])->toBeFalse();
    expect(Player::where('gamertag', 'Alice')->first()->unban_tokens)->toBe(0);
});
