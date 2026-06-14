<?php

use App\Models\Player;
use App\Services\Lifecycle\MentionSubstitutor;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('replaces PLAYER with a mention when linked, backtick when not', function () {
    Player::create(['gamertag' => 'Linked', 'discord_user_id' => '999']);
    $sub = new MentionSubstitutor();

    $linked = $sub->apply('RIP {{PLAYER}} forever', ['{{PLAYER}}' => 'Linked']);
    $plain = $sub->apply('RIP {{PLAYER}} forever', ['{{PLAYER}}' => 'Unknown']);

    expect($linked)->toBe('RIP <@999> forever');
    expect($plain)->toBe('RIP `Unknown` forever');
});

it('replaces multiple placeholders and leaves text without placeholders untouched', function () {
    Player::create(['gamertag' => 'K', 'discord_user_id' => '7']);
    $out = (new MentionSubstitutor())->apply(
        '{{KILLER}} dropped {{PLAYER}}',
        ['{{PLAYER}}' => 'V', '{{KILLER}}' => 'K']
    );
    expect($out)->toBe('<@7> dropped `V`');
});
