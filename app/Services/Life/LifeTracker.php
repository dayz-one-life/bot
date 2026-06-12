<?php

namespace App\Services\Life;

use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;

class LifeTracker
{
    public function connect(string $gamertag, \DateTimeImmutable $ts): void
    {
        $player = Player::firstOrCreate(
            ['gamertag' => $gamertag],
            ['first_seen_at' => $ts, 'last_seen_at' => $ts]
        );
        $this->touch($player, $ts);

        if ($open = $player->openSession()) {
            $this->closeSession($open, $ts, 'superseded');
        }

        $life = $player->openLife() ?? Life::create([
            'player_id' => $player->id,
            'started_at' => $ts,
        ]);

        GameSession::create([
            'player_id' => $player->id,
            'life_id' => $life->id,
            'connected_at' => $ts,
        ]);
    }

    /**
     * @param array{victim:string,cause:string,killer:?string} $death
     */
    public function death(array $death, \DateTimeImmutable $ts): void
    {
        $player = Player::firstOrCreate(
            ['gamertag' => $death['victim']],
            ['first_seen_at' => $ts, 'last_seen_at' => $ts]
        );
        $this->touch($player, $ts);

        if ($open = $player->openSession()) {
            $this->closeSession($open, $ts, 'clean');
        }

        $life = $player->openLife() ?? Life::create([
            'player_id' => $player->id,
            'started_at' => $ts,
        ]);

        $life->update([
            'ended_at' => $ts,
            'death_cause' => $death['cause'],
            'death_by_gamertag' => $death['killer'],
        ]);
    }

    public function disconnect(string $gamertag, \DateTimeImmutable $ts): void
    {
        $player = Player::where('gamertag', $gamertag)->first();
        if (!$player) return;
        $this->touch($player, $ts);

        if ($open = $player->openSession()) {
            $this->closeSession($open, $ts, 'clean');
        }
    }

    protected function closeSession(GameSession $session, \DateTimeImmutable $ts, string $reason): void
    {
        $duration = max(0, $ts->getTimestamp() - $session->connected_at->getTimestamp());
        $session->update([
            'disconnected_at' => $ts,
            'duration_seconds' => $duration,
            'close_reason' => $reason,
        ]);
        Life::where('id', $session->life_id)->increment('playtime_seconds', $duration);
    }

    protected function touch(Player $player, \DateTimeImmutable $ts): void
    {
        $data = ['last_seen_at' => $ts];
        if ($player->first_seen_at === null) $data['first_seen_at'] = $ts;
        $player->forceFill($data)->save();
    }
}
