<?php

it('ships a complete set of non-empty personality pools', function () {
    $keys = [
        'bounty.placed', 'bounty.moved', 'bounty.claimed', 'bounty.ended',
        'bounty.dm.placed', 'bounty.dm.moved', 'bounty.dm.claimed',
        'ban.death', 'ban.manual', 'ban.extended', 'ban.unbanned',
        'ban.dm.death', 'ban.dm.manual', 'ban.dm.unbanned',
        'connection.connected', 'connection.disconnected', 'connection.disconnected_nodur',
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

it('keeps bounty.ended neutral about payouts', function () {
    foreach (config('personality.bounty.ended') as $line) {
        foreach (['token', 'reward', 'paid', 'claim'] as $word) {
            expect(stripos($line, $word))->toBeFalse();
        }
    }
});
