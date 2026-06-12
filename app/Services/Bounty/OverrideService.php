<?php

namespace App\Services\Bounty;

use App\Models\AssociateOverride;
use App\Models\Player;

class OverrideService
{
    /** @return string 'ok' | 'not_found' */
    public function set(string $tagA, string $tagB, bool $force): string
    {
        [$lo, $hi] = $this->normalize($tagA, $tagB);
        if ($lo === null) return 'not_found';

        AssociateOverride::updateOrCreate(
            ['player_a_id' => $lo, 'player_b_id' => $hi],
            ['force' => $force],
        );
        return 'ok';
    }

    /** @return string 'ok' | 'not_found' */
    public function clear(string $tagA, string $tagB): string
    {
        [$lo, $hi] = $this->normalize($tagA, $tagB);
        if ($lo === null) return 'not_found';

        AssociateOverride::where('player_a_id', $lo)->where('player_b_id', $hi)->delete();
        return 'ok';
    }

    /** @return array{0:?int,1:?int} ordered ids, or [null,null] if either gamertag is unknown. */
    private function normalize(string $tagA, string $tagB): array
    {
        $a = Player::where('gamertag', $tagA)->first();
        $b = Player::where('gamertag', $tagB)->first();
        if (! $a || ! $b) return [null, null];

        return $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];
    }
}
