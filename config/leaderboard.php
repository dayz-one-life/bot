<?php

return [
    'enabled' => filter_var(env('LEADERBOARD_ENABLED', true), FILTER_VALIDATE_BOOL),
    // No bans-channel fallback: unset/empty => null => the notifier no-ops (feature off).
    'channel_id' => env('LEADERBOARD_CHANNEL_ID') ?: null,
    'refresh_minutes' => (int) env('LEADERBOARD_REFRESH_MINUTES', 15),
    'top_count' => (int) env('LEADERBOARD_TOP_COUNT', 25),
];
