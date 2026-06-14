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

it('returns seven boards in the canonical order with key, title, description', function () {
    $boards = $this->composer->composeBoards(lbBoards());

    expect($boards)->toHaveCount(7);
    expect(array_column($boards, 'key'))->toBe([
        'alive', 'all_time', 'kills', 'streak', 'distance', 'bunker_visits', 'quickest_bunker',
    ]);
    foreach ($boards as $b) {
        expect($b['title'])->toBeString()->not->toBe('');
        expect($b['description'])->toBeString()->not->toBe('');
    }
});

it('puts ranked rows in the description and never @-mentions', function () {
    $alive = $this->composer->composeBoards(lbBoards())[0];

    expect($alive['title'])->toBe('🫀 Longest Life · Still Alive');
    expect($alive['description'])->toContain('1. `Alice` — 1h 23m');
    expect($alive['description'])->toContain('2. `Bob` — <1m');
    expect($alive['description'])->not->toContain('<@');
});

it('formats kill counts (singular/plural) and distance rows', function () {
    $boards = collect($this->composer->composeBoards(lbBoards()))->keyBy('key');

    expect($boards['kills']['description'])->toContain('1. `Bob` — 3 kills');
    expect($boards['kills']['description'])->toContain('2. `Alice` — 1 kill');
    expect($boards['distance']['description'])->toContain('`Bob` (M24) — 413m → `Carol`');
});

it('renders an empty board as a placeholder (personality line still present)', function () {
    $input = lbBoards();
    $input['streak'] = [];
    $boards = collect($this->composer->composeBoards($input))->keyBy('key');

    expect($boards['streak']['description'])->toContain('*No entries yet*');
    // Personality line is the first line of the leaderboard.streak pool.
    expect($boards['streak']['description'])->toContain(config('personality.leaderboard.streak')[0]);
});

it('renders the two bunker boards with correct nouns and duration', function () {
    $boards = collect($this->composer->composeBoards(lbBoards()))->keyBy('key');

    expect($boards['bunker_visits']['title'])->toBe('🚪 Most Bunker Visits');
    expect($boards['bunker_visits']['description'])->toContain('`Alice` — 2 visits');
    expect($boards['bunker_visits']['description'])->toContain('`Bob` — 1 visit'); // singular

    expect($boards['quickest_bunker']['title'])->toBe('⏱️ Quickest New Life → Bunker');
    expect($boards['quickest_bunker']['description'])->toContain('`Bob`');
    expect($boards['quickest_bunker']['description'])->toContain('2m'); // SessionDuration
});
