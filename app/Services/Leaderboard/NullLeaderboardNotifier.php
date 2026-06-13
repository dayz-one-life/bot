<?php

namespace App\Services\Leaderboard;

class NullLeaderboardNotifier implements LeaderboardNotifier
{
    /** @var array{title:string, description:string, fields:array}|null */
    public ?array $lastPayload = null;

    public function publish(array $payload): void
    {
        $this->lastPayload = $payload;
    }
}
