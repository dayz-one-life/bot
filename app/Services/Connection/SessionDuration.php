<?php

namespace App\Services\Connection;

/**
 * Formats a session length in seconds as a compact human string for chat output.
 * Kept pure/static so it has a unit test without a Discord gateway.
 */
class SessionDuration
{
    public static function human(int $seconds): string
    {
        if ($seconds < 60) {
            return '<1m';
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return "{$minutes}m";
        }

        $hours = intdiv($minutes, 60);
        $rem = $minutes % 60;

        return "{$hours}h {$rem}m";
    }
}
