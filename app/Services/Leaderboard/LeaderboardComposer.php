<?php

namespace App\Services\Leaderboard;

use App\Services\Connection\SessionDuration;
use App\Services\Personality\MessagePicker;

/**
 * Turns the seven board row-sets into an ordered list of Discord-agnostic board
 * payloads ({key,title,description}) — one per leaderboard message. Pure/testable;
 * the notifier turns each into an actual Discord Embed. Players render as plain
 * backticked gamertags; the leaderboard NEVER @-mentions (high-frequency edited
 * messages — an intentional exception to the "public posts mention" rule).
 */
class LeaderboardComposer
{
    private MessagePicker $picker;

    public function __construct(?MessagePicker $picker = null)
    {
        $this->picker = $picker ?? new MessagePicker();
    }

    /**
     * @param  array{alive:array, all_time:array, kills:array, streak:array, distance:array, bunker_visits:array, quickest_bunker:array}  $boards
     * @return array<int, array{key:string, title:string, description:string}>  Ordered, top→bottom.
     */
    public function composeBoards(array $boards): array
    {
        return [
            $this->board('alive', '🫀 Longest Life · Still Alive', $this->durationRows($boards['alive'])),
            $this->board('all_time', '⏳ Longest Life · All Time', $this->durationRows($boards['all_time'])),
            $this->board('kills', '🔫 Most Kills', $this->countRows($boards['kills'], 'kills')),
            $this->board('streak', '🩸 Longest Kill Streak', $this->countRows($boards['streak'], 'streak')),
            $this->board('distance', '🎯 Longest Kills', $this->distanceRows($boards['distance'])),
            $this->board('bunker_visits', '🚪 Most Bunker Visits', $this->countRows($boards['bunker_visits'], 'bunker_visits', 'visit', 'visits')),
            $this->board('quickest_bunker', '⏱️ Quickest New Life → Bunker', $this->durationRows($boards['quickest_bunker'])),
        ];
    }

    /** @return array{key:string, title:string, description:string} */
    private function board(string $key, string $title, string $rows): array
    {
        $line = $this->picker->pick("leaderboard.{$key}", [], 'The standings, fresh off the server.');

        return [
            'key' => $key,
            'title' => $title,
            'description' => $line."\n\n".$rows,
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
    private function countRows(array $rows, string $key, string $singular = 'kill', string $plural = 'kills'): string
    {
        if ($rows === []) {
            return '*No entries yet*';
        }

        $lines = [];
        foreach ($rows as $i => $r) {
            $n = (int) $r[$key];
            $noun = $n === 1 ? $singular : $plural;
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
