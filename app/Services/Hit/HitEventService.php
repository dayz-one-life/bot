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
 * IDEMPOTENT: a hit is keyed by (victim, attacker, attacker_type, body_part, occurred_at); an
 * identical row is skipped (returns null). This makes `adm:backfill-hits` safe to re-run AND safe to
 * run over a window that live ingest already covered — without it, re-derived hits would double the
 * newspaper's infected_attacks / pvp_hits counts. (Mirrors BunkerVisitService's dedup intent; the
 * second granularity means two truly-distinct same-second identical hits collapse to one — an
 * acceptable rare undercount versus doubling everything.)
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

        $tsC = CarbonImmutable::instance($ts);

        $exists = HitEvent::query()
            ->where('victim_gamertag', $hit['victim'])
            ->where('attacker_type', $hit['attacker_type'])
            ->where('occurred_at', $tsC)
            ->where(fn ($q) => $hit['attacker_gamertag'] === null
                ? $q->whereNull('attacker_gamertag')
                : $q->where('attacker_gamertag', $hit['attacker_gamertag']))
            ->where(fn ($q) => $hit['body_part'] === null
                ? $q->whereNull('body_part')
                : $q->where('body_part', $hit['body_part']))
            ->exists();
        if ($exists) {
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
            'occurred_at' => $tsC,
        ]);
    }
}
