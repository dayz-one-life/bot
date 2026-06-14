<?php

use App\Services\Leaderboard\LeaderboardComposer;
use App\Services\Personality\MessagePicker;

beforeEach(function () {
    MessagePicker::reset();
    // Deterministic picker: always the first line of a pool.
    $this->composer = new LeaderboardComposer(new MessagePicker(fn (array $pool, ?int $avoid) => 0));
});

function lbBoards(): array
{
    return [
        'alive' => [['gamertag' => 'Alice', 'seconds' => 5000], ['gamertag' => 'Bob', 'seconds' => 45]],
        'all_time' => [['gamertag' => 'Carol', 'seconds' => 7200]],
        'kills' => [['gamertag' => 'Bob', 'kills' => 3], ['gamertag' => 'Alice', 'kills' => 1]],
        'streak' => [['gamertag' => 'Bob', 'streak' => 2]],
        'distance' => [['killer' => 'Bob', 'victim' => 'Carol', 'weapon' => 'M24', 'distance' => 412.7]],
        'bunker_visits' => [['gamertag' => 'Alice', 'bunker_visits' => 2], ['gamertag' => 'Bob', 'bunker_visits' => 1]],
        'quickest_bunker' => [['gamertag' => 'Bob', 'seconds' => 120]],
    ];
}

it('builds a seven-field payload with a title and description', function () {
    $payload = $this->composer->compose(lbBoards());

    expect($payload['title'])->toContain('Leaderboard');
    expect($payload['description'])->toBeString()->not->toBe('');
    expect($payload['fields'])->toHaveCount(7);
});

it('formats durations and never @-mentions (plain backticked gamertags)', function () {
    $fields = $this->composer->compose(lbBoards())['fields'];

    // Field 0 = alive board
    expect($fields[0]['value'])->toContain('1. `Alice` — 1h 23m');
    expect($fields[0]['value'])->toContain('2. `Bob` — <1m');
    expect($fields[0]['value'])->not->toContain('<@');
});

it('formats kill counts with singular/plural and distance rows', function () {
    $fields = $this->composer->compose(lbBoards())['fields'];

    // Field 2 = most kills
    expect($fields[2]['value'])->toContain('1. `Bob` — 3 kills');
    expect($fields[2]['value'])->toContain('2. `Alice` — 1 kill');

    // Field 4 = longest distance kill
    expect($fields[4]['value'])->toContain('`Bob` (M24) — 413m → `Carol`');
});

it('renders an empty board as a placeholder', function () {
    $boards = lbBoards();
    $boards['streak'] = [];

    $fields = $this->composer->compose($boards)['fields'];

    // Field 3 = streak
    expect($fields[3]['value'])->toBe('*No entries yet*');
});

it('renders the two bunker boards with correct nouns and duration', function () {
    $fields = $this->composer->compose(lbBoards())['fields'];
    $names = array_column($fields, 'name');

    expect($names)->toContain('🚪 Most Bunker Visits')
        ->and($names)->toContain('⏱️ Quickest New Life → Bunker');

    $visitsField = collect($fields)->firstWhere('name', '🚪 Most Bunker Visits');
    expect($visitsField['value'])->toContain('`Alice` — 2 visits')
        ->and($visitsField['value'])->toContain('`Bob` — 1 visit'); // singular

    $quickField = collect($fields)->firstWhere('name', '⏱️ Quickest New Life → Bunker');
    expect($quickField['value'])->toContain('`Bob`')
        ->and($quickField['value'])->toContain('2m'); // duration rendered via SessionDuration
});
