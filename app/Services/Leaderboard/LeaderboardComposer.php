<?php

namespace App\Services\Leaderboard;

use App\Services\Connection\SessionDuration;
use App\Services\Personality\MessagePicker;

/**
 * Turns the five board row-sets into a Discord-agnostic embed payload
 * (title, description, list of {name,value} fields). Pure/testable — the
 * notifier turns this into an actual Discord Embed. Players are rendered as
 * plain backticked gamertags; the leaderboard NEVER @-mentions (high-frequency
 * edited message — an intentional exception to the "public posts mention" rule).
 */
class LeaderboardComposer
{
    private MessagePicker $picker;

    public function __construct(?MessagePicker $picker = null)
    {
        $this->picker = $picker ?? new MessagePicker();
    }

    /**
     * @param  array{alive:array, all_time:array, kills:array, streak:array, distance:array}  $boards
     * @return array{title:string, description:string, fields:array<int, array{name:string, value:string}>}
     */
    public function compose(array $boards): array
    {
        return [
            'title' => '🏆 One Life Leaderboards',
            'description' => $this->picker->pick('leaderboard.intro', [], 'The standings, fresh off the server.'),
            'fields' => [
                ['name' => '🫀 Longest Life · Still Alive', 'value' => $this->durationRows($boards['alive'])],
                ['name' => '⏳ Longest Life · All Time', 'value' => $this->durationRows($boards['all_time'])],
                ['name' => '🔫 Most Kills', 'value' => $this->countRows($boards['kills'], 'kills')],
                ['name' => '🩸 Longest Kill Streak', 'value' => $this->countRows($boards['streak'], 'streak')],
                ['name' => '🎯 Longest Kills', 'value' => $this->distanceRows($boards['distance'])],
            ],
        ];
    }

    /** @param array<int, array{gamertag:string, seconds:int}> $rows */
    private function durationRows(array $rows): string
    {
        if ($rows === []) {
            return '*No entries yet*';
        }

        $lines = [];
        foreach ($rows as $i => $r) {
            $lines[] = ($i + 1).". `{$r['gamertag']}` — ".SessionDuration::human((int) $r['seconds']);
        }

        return implode("\n", $lines);
    }

    /** @param array<int, array{gamertag:string}> $rows */
    private function countRows(array $rows, string $key): string
    {
        if ($rows === []) {
            return '*No entries yet*';
        }

        $lines = [];
        foreach ($rows as $i => $r) {
            $n = (int) $r[$key];
            $noun = $n === 1 ? 'kill' : 'kills';
            $lines[] = ($i + 1).". `{$r['gamertag']}` — {$n} {$noun}";
        }

        return implode("\n", $lines);
    }

    /** @param array<int, array{killer:string, victim:string, weapon:?string, distance:float}> $rows */
    private function distanceRows(array $rows): string
    {
        if ($rows === []) {
            return '*No entries yet*';
        }

        $lines = [];
        foreach ($rows as $i => $r) {
            $dist = round((float) $r['distance']).'m';
            $weapon = ! empty($r['weapon']) ? " ({$r['weapon']})" : '';
            $lines[] = ($i + 1).". `{$r['killer']}`{$weapon} — {$dist} → `{$r['victim']}`";
        }

        return implode("\n", $lines);
    }
}
