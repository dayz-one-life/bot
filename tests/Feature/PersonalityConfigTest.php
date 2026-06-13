<?php

it('ships a complete set of non-empty personality pools', function () {
    $keys = [
        'bounty.placed', 'bounty.moved', 'bounty.claimed', 'bounty.ended',
        'bounty.dm.placed', 'bounty.dm.moved', 'bounty.dm.claimed',
        'ban.death', 'ban.manual', 'ban.extended', 'ban.unbanned',
        'ban.dm.death', 'ban.dm.manual', 'ban.dm.unbanned',
        'connection.connected', 'connection.disconnected', 'connection.disconnected_nodur',
        'death.pvp', 'death.pvp_noweapon', 'death.suicide', 'death.environment', 'death.misc',
    ];

    foreach ($keys as $key) {
        $pool = config("personality.{$key}");
        expect($pool)->toBeArray();
        expect(count($pool))->toBeGreaterThanOrEqual(10);
        foreach ($pool as $line) {
            expect($line)->toBeString();
            expect(trim($line))->not->toBe('');
        }
    }
});

it('death pools carry the tokens their messages need', function () {
    $required = [
        'death.pvp' => [':killer', ':victim', ':weapon', ':distancem', ':expires'],
        'death.pvp_noweapon' => [':killer', ':victim', ':expires'],
        'death.suicide' => [':victim', ':expires'],
        'death.environment' => [':victim', ':expires'],
        'death.misc' => [':victim', ':cause', ':expires'],
    ];

    foreach ($required as $key => $tokens) {
        foreach (config("personality.{$key}") as $line) {
            foreach ($tokens as $token) {
                expect(str_contains($line, $token))->toBeTrue("{$key} line missing {$token}: {$line}");
            }
        }
    }
});

it('keeps bounty.ended neutral about payouts', function () {
    foreach (config('personality.bounty.ended') as $line) {
        foreach (['token', 'reward', 'paid', 'claim'] as $word) {
            expect(stripos($line, $word))->toBeFalse();
        }
    }
});
