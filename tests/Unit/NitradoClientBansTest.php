<?php

use App\Services\Nitrado\NitradoClient;
use Illuminate\Support\Facades\Http;

it('reads the ban list from general.bans', function () {
    Http::fake([
        '*/gameservers/settings' => Http::response(['status' => 'success', 'data' => [
            'settings' => ['general' => ['bans' => "Alice\r\nBob"]],
        ]]),
    ]);

    expect((new NitradoClient('t', 1))->getBans())->toBe(['Alice', 'Bob']);
});

it('adds a gamertag to the ban list idempotently', function () {
    Http::fake([
        '*/gameservers/settings' => function ($request) {
            if ($request->method() === 'POST') {
                return Http::response(['status' => 'success', 'data' => ['settings' => []]]);
            }
            return Http::response(['status' => 'success', 'data' => ['settings' => ['general' => ['bans' => "Alice"]]]]);
        },
    ]);

    (new NitradoClient('t', 1))->addBan('Bob');

    Http::assertSent(fn ($r) => $r->method() === 'POST'
        && $r['category'] === 'general' && $r['key'] === 'bans'
        && $r['value'] === "Alice\r\nBob");
});

it('does not re-add a gamertag already banned', function () {
    Http::fake([
        '*/gameservers/settings' => Http::response(['status' => 'success', 'data' => [
            'settings' => ['general' => ['bans' => "Alice"]],
        ]]),
    ]);

    (new NitradoClient('t', 1))->addBan('Alice');
    Http::assertNotSent(fn ($r) => $r->method() === 'POST');
});

it('removes a gamertag from the ban list', function () {
    Http::fake([
        '*/gameservers/settings' => function ($request) {
            if ($request->method() === 'POST') return Http::response(['status' => 'success', 'data' => []]);
            return Http::response(['status' => 'success', 'data' => ['settings' => ['general' => ['bans' => "Alice\r\nBob"]]]]);
        },
    ]);

    (new NitradoClient('t', 1))->removeBan('Alice');
    Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['value'] === "Bob");
});
