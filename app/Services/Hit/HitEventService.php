<?php

namespace App\Services\Hit;

use App\Models\HitEvent;
use App\Models\Player;
use Carbon\CarbonImmutable;

/**
 * Records non-fatal/fatal hit events parsed from ADM "hit by" lines. DB-only; not gated by
 * BAN_DRY_RUN. Victim is linked to a known player when the gamertag is already tracked, otherwise
 * stored denormalized (we do NOT create player rows from hits — a hit alone is weak evidence and
 * connect/death events own player creation). No-ops when hit tracking is disabled.
 *
 * @phpstan-type ParsedHit array{victim:string,victim_hp:?int,victim_x:?float,victim_y:?float,body_part:?string,attacker_gamertag:?string,attacker_type:string,attacker_label:?string}
 */
class HitEventService
{
    /** @param ParsedHit $hit */
    public function record(array $hit, \DateTimeImmutable $ts): ?HitEvent
    {
        if (! config('hits.enabled', true)) {
            return null;
        }

        $victim = Player::where('gamertag', $hit['victim'])->first();

        return HitEvent::create([
            'victim_player_id' => $victim?->id,
            'victim_gamertag' => $hit['victim'],
            'attacker_gamertag' => $hit['attacker_gamertag'],
            'attacker_type' => $hit['attacker_type'],
            'attacker_label' => $hit['attacker_label'],
            'body_part' => $hit['body_part'],
            'victim_hp' => $hit['victim_hp'],
            'victim_x' => $hit['victim_x'],
            'victim_y' => $hit['victim_y'],
            'occurred_at' => CarbonImmutable::instance($ts),
        ]);
    }
}
