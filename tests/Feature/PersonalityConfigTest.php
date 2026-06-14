<?php

it('ships a complete set of non-empty personality pools', function () {
    $keys = [
        'bounty.placed', 'bounty.moved', 'bounty.claimed', 'bounty.ended',
        'bounty.dm.placed', 'bounty.dm.moved', 'bounty.dm.claimed',
        'ban.death', 'ban.manual', 'ban.extended', 'ban.unbanned',
        'ban.dm.death', 'ban.dm.manual', 'ban.dm.unbanned',
        'connection.connected', 'connection.disconnected', 'connection.disconnected_nodur',
        'leaderboard.intro',
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

it('ships well-formed birth and eulogy fallback pools', function () {
    // The lifecycle birth/eulogy pools are STRUCTURED (headline + body) and used only as the
    // canned fallback when OpenRouter is unavailable. They carry the {{PLAYER}}/{{KILLER}}
    // placeholders the announcer substitutes (every body names the subject).
    $structured = [
        'birth.fallback',
        'eulogy.pvp', 'eulogy.suicide', 'eulogy.environment', 'eulogy.misc',
    ];

    foreach ($structured as $key) {
        $pool = config("personality.{$key}");
        expect($pool)->toBeArray();
        expect(count($pool))->toBeGreaterThanOrEqual(3);
        foreach ($pool as $entry) {
            expect($entry)->toHaveKeys(['headline', 'body']);
            expect(trim($entry['headline']))->not->toBe('');
            expect(trim($entry['body']))->not->toBe('');
            expect($entry['body'])->toContain('{{PLAYER}}');
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
