<?php

namespace App\Services\Bunker;

use App\Models\BunkerVisit;
use App\Models\Life;
use App\Models\Player;
use Carbon\CarbonImmutable;

/**
 * Records bunker visits (RestrictedAreaBunkerEntrance teleports). De-dupes rapid
 * relogs inside the bunker via a per-player cooldown. Associates the life whose
 * window [started_at, ended_at) contains the visit — correct for both live ingest
 * (the open life) and history backfill (the matching historical life). DB-only;
 * not gated by BAN_DRY_RUN.
 */
class BunkerVisitService
{
    public function record(string $gamertag, \DateTimeImmutable $ts): ?BunkerVisit
    {
        if (! config('bunker.enabled', true)) {
            return null;
        }

        // firstOrCreate (not skip-if-unknown like PositionRecorder): backfill may see a bunker visit for a gamertag before any other event has created the player row.
        $player = Player::firstOrCreate(['gamertag' => $gamertag]);
        $tsC = CarbonImmutable::instance($ts);

        $cooldownMinutes = (int) config('bunker.cooldown_minutes', 60);
        $windowStart = $tsC->subMinutes($cooldownMinutes);

        $recent = BunkerVisit::where('player_id', $player->id)
            ->where('visited_at', '>=', $windowStart)
            ->exists();
        if ($recent) {
            return null;
        }

        $life = Life::where('player_id', $player->id)
            ->where('started_at', '<=', $tsC)
            ->where(function ($q) use ($tsC) {
                $q->whereNull('ended_at')->orWhere('ended_at', '>=', $tsC);
            })
            ->orderByDesc('started_at')
            ->first();

        return BunkerVisit::create([
            'player_id' => $player->id,
            'life_id'   => $life?->id,
            'visited_at' => $tsC,
        ]);
    }
}
