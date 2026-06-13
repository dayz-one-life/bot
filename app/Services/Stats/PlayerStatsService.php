<?php

namespace App\Services\Stats;

use App\Models\Life;
use App\Models\Player;
use Carbon\CarbonImmutable;

class PlayerStatsService
{
    /**
     * @return array{found:bool, gamertag?:string, lives?:int, deaths?:int,
     *               playtime_seconds?:int, current_life_seconds?:?int, alive?:bool,
     *               linked?:bool, last_seen_at?:?string,
     *               current_life_sessions?:array<int, array{connected_at:string, duration_seconds:int, is_open:bool}>,
     *               current_life_session_total?:int}
     */
    /** Resolve a Discord user id to their linked gamertag, or null if not linked. */
    public function gamertagForDiscordUser(string $discordUserId): ?string
    {
        return Player::where('discord_user_id', $discordUserId)->value('gamertag');
    }

    public function statsFor(string $gamertag): array
    {
        $player = Player::where('gamertag', $gamertag)->withCount([
            'lives',
            'lives as deaths_count' => fn ($q) => $q->whereNotNull('ended_at'),
            'lives as open_lives_count' => fn ($q) => $q->whereNull('ended_at'),
        ])->first();

        if (! $player) {
            return ['found' => false];
        }

        $alive = $player->open_lives_count > 0;
        $openLife = $alive
            ? $player->lives()->whereNull('ended_at')->orderByDesc('started_at')->first()
            : null;

        return [
            'found' => true,
            'gamertag' => $player->gamertag,
            'lives' => (int) $player->lives_count,
            'deaths' => (int) $player->deaths_count,
            'playtime_seconds' => (int) $player->lives()->sum('playtime_seconds'),
            'current_life_seconds' => $openLife?->playtime_seconds !== null
                ? (int) $openLife->playtime_seconds
                : null,
            'alive' => $alive,
            'linked' => $player->discord_user_id !== null,
            'last_seen_at' => $player->last_seen_at?->toIso8601String(),
            'current_life_sessions' => $this->currentLifeSessions($openLife),
            'current_life_session_total' => $openLife
                ? (int) $openLife->sessions()->count()
                : 0,
        ];
    }

    /**
     * The (at most 12 most-recent) sessions of the open life, oldest-first.
     *
     * @return array<int, array{connected_at:string, duration_seconds:int, is_open:bool}>
     */
    private function currentLifeSessions(?Life $openLife): array
    {
        if (! $openLife) {
            return [];
        }

        // Take the 12 most recent by connected_at, then re-sort ascending for display.
        $sessions = $openLife->sessions()
            ->orderByDesc('connected_at')
            ->limit(12)
            ->get()
            ->sortBy('connected_at')
            ->values();

        return $sessions->map(function ($session) {
            $isOpen = $session->disconnected_at === null;

            // For closed sessions use the stored value. For the open session, compute
            // elapsed-so-far via raw unix-timestamp subtraction — the same idiom
            // LifeTracker uses (app/Services/Life/LifeTracker.php:81) to avoid Carbon 3's
            // signed diffInSeconds.
            $duration = $session->duration_seconds !== null
                ? (int) $session->duration_seconds
                : ($isOpen
                    ? max(0, CarbonImmutable::now()->getTimestamp() - $session->connected_at->getTimestamp())
                    : 0);

            return [
                'connected_at' => $session->connected_at->toIso8601String(),
                'duration_seconds' => (int) $duration,
                'is_open' => $isOpen,
            ];
        })->all();
    }
}
