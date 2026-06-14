<?php

namespace App\Services\Life;

use App\Models\GameSession;
use App\Models\Life;
use App\Models\Player;

class LifeTracker
{
    public function connect(string $gamertag, \DateTimeImmutable $ts): void
    {
        $player = Player::firstOrCreate(['gamertag' => $gamertag]);
        $this->touch($player, $ts); // sole writer of first_seen_at / last_seen_at

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
     * @param array{victim:string,cause:string,killer:?string,weapon?:?string,distance?:?float} $death
     */
    public function death(array $death, \DateTimeImmutable $ts, ?string $log = null): void
    {
        $player = Player::where('gamertag', $death['victim'])->first();
        if (! $player) return; // never-seen player, only a (duplicate) death line — ignore
        $this->touch($player, $ts);

        if ($open = $player->openSession()) {
            $this->closeSession($open, $ts, 'clean');
        }

        // A death only ends an OPEN life. With no open life this is a duplicate
        // death-log line for an already-ended life (DayZ logs some deaths as
        // multiple lines) — ignore it rather than fabricating a zero-duration life.
        $life = $player->openLife();
        if (! $life) return;

        $life->update([
            'ended_at' => $ts,
            'death_cause' => $death['cause'],
            'death_by_gamertag' => $death['killer'],
            'death_weapon' => $death['weapon'] ?? null,
            'death_distance' => $death['distance'] ?? null,
            'death_log' => $log,
        ]);
    }

    public function reboot(\DateTimeImmutable $ts): void
    {
        GameSession::whereNull('disconnected_at')->get()->each(
            fn (GameSession $s) => $this->closeSession($s, $ts, 'reboot')
        );
    }

    public function disconnect(string $gamertag, \DateTimeImmutable $ts): ?GameSession
    {
        $player = Player::where('gamertag', $gamertag)->first();
        if (! $player) return null;
        $this->touch($player, $ts);

        if ($open = $player->openSession()) {
            $this->closeSession($open, $ts, 'clean');
            return $open; // closeSession set duration_seconds/close_reason on this instance
        }

        return null;
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
