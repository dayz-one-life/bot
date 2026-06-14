<?php

namespace App\Services\Online;

use App\Models\GameSession;
use App\Services\Life\LivePlaytime;
use Carbon\CarbonImmutable;

/**
 * Read-only snapshot of who is currently online: one row per open game_session
 * (disconnected_at IS NULL). session_seconds is elapsed-since-connect; life_seconds
 * is the open life's live playtime (stored + open session so far). Sorted by
 * longest current session first. Pure DB read — no Discord, no side effects.
 */
class OnlineRosterQuery
{
    /**
     * @return array<int, array{gamertag:string, session_seconds:int, life_seconds:int}>
     */
    public function rows(): array
    {
        $now = CarbonImmutable::now()->getTimestamp();

        $sessions = GameSession::with('player')
            ->whereNull('disconnected_at')
            ->get();

        $rows = [];
        foreach ($sessions as $session) {
            $player = $session->player;
            if (! $player) {
                continue;
            }

            $sessionSeconds = max(0, $now - $session->connected_at->getTimestamp());

            $life = $player->openLife();
            $lifeSeconds = $life ? LivePlaytime::forLife($life) : $sessionSeconds;

            $rows[] = [
                'gamertag' => $player->gamertag,
                'session_seconds' => $sessionSeconds,
                'life_seconds' => $lifeSeconds,
            ];
        }

        usort($rows, fn ($a, $b) => $b['session_seconds'] <=> $a['session_seconds']);

        return $rows;
    }
}
