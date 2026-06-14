<?php

namespace App\Services\Leaderboard;

interface LeaderboardNotifier
{
    /**
     * Publish (post or edit) the leaderboard's 7 board messages.
     *
     * @param  array<int, array{key:string, title:string, description:string}>  $payloads  Ordered, top→bottom.
     */
    public function publish(array $payloads): void;
}
