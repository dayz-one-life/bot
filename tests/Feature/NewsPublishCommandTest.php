<?php

use App\Services\State\BotState;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('dry-run prints an issue without publishing or stamping state', function () {
    Http::fake(['*' => Http::response('nope', 500)]); // canned fallback, no real API
    (new BotState())->set('go_live_at', '2026-01-01T00:00:00+00:00');

    $this->artisan('news:publish --dry-run --force')->assertExitCode(0);

    // dry-run must NOT persist the weekly stamp
    expect((new BotState())->get('last_newspaper_week'))->toBeNull();
});
