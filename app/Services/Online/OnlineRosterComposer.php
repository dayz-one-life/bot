<?php

namespace App\Services\Online;

use App\Services\Connection\SessionDuration;

/**
 * Turns online-roster rows into a Discord-agnostic embed payload {title, description}.
 * Pure/testable — the notifier turns this into an actual Discord Embed. Players are
 * rendered as plain backticked gamertags; the roster NEVER @-mentions (high-volume,
 * frequently-edited message — the same intentional exception the old connection feed had).
 */
class OnlineRosterComposer
{
    /**
     * @param  array<int, array{gamertag:string, session_seconds:int, life_seconds:int}>  $rows
     * @return array{title:string, description:string}
     */
    public function compose(array $rows): array
    {
        if ($rows === []) {
            return [
                'title' => '🟢 Online — 0',
                'description' => "Nobody's online right now.",
            ];
        }

        $lines = [];
        foreach ($rows as $r) {
            $session = SessionDuration::human((int) $r['session_seconds']);
            $life = SessionDuration::human((int) $r['life_seconds']);
            $lines[] = "`{$r['gamertag']}` · on {$session} · alive {$life}";
        }

        return [
            'title' => '🟢 Online — '.count($rows),
            'description' => implode("\n", $lines),
        ];
    }
}
