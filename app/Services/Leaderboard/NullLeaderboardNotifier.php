<?php

namespace App\Services\Leaderboard;

class NullLeaderboardNotifier implements LeaderboardNotifier
{
    /** @var array<int, array{key:string, title:string, description:string}>|null */
    public ?array $lastPayloads = null;

    public function publish(array $payloads): void
    {
        $this->lastPayloads = $payloads;
    }
}
