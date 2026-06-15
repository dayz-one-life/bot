<?php

use App\Services\State\BotState;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('dry-run prints an issue without publishing or stamping state', function () {
    Http::fake(['*' => Http::response('nope', 500)]); // canned fallback, no real API
    (new BotState())->set('go_live_at', '2026-01-01T00:00:00+00:00');

    $this->artisan('news:publish --dry-run --force')
        ->expectsOutputToContain('THE ONE LIFE TRIBUNE')
        ->assertExitCode(0);

    // must NOT persist the weekly stamp
    expect((new BotState())->get('last_newspaper_week'))->toBeNull();
});

it('prints a preview note instead of claiming a Discord post', function () {
    Http::fake(['*' => Http::response('nope', 500)]);
    (new BotState())->set('go_live_at', '2026-01-01T00:00:00+00:00');

    $this->artisan('news:publish')
        ->expectsOutputToContain('Preview only')
        ->assertExitCode(0);

    // state must remain unstamped
    expect((new BotState())->get('last_newspaper_week'))->toBeNull();
    expect((new BotState())->get('newspaper_issue_count'))->toBeNull();
});
