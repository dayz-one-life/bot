<?php

namespace App\Services\Leaderboard;

interface LeaderboardNotifier
{
    /**
     * Publish (post or edit) the leaderboard.
     *
     * @param  array{title:string, description:string, fields:array<int, array{name:string, value:string}>}  $payload
     */
    public function publish(array $payload): void;
}
