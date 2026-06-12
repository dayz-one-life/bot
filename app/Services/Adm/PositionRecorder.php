<?php

namespace App\Services\Adm;

use App\Models\Player;
use App\Models\PlayerPosition;

class PositionRecorder
{
    /** Store a position sample. No-op for a gamertag we've never seen (no player row yet). */
    public function record(string $gamertag, float $x, float $y, \DateTimeImmutable $ts): void
    {
        $player = Player::where('gamertag', $gamertag)->first();
        if (! $player) return;

        PlayerPosition::create([
            'player_id' => $player->id,
            'x' => $x,
            'y' => $y,
            'recorded_at' => $ts,
        ]);
    }
}
