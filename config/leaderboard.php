<?php

return [
    'enabled' => filter_var(env('LEADERBOARD_ENABLED', true), FILTER_VALIDATE_BOOL),
    'channel_id' => env('LEADERBOARD_CHANNEL_ID') ?: env('BANS_CHANNEL_ID'),
    'refresh_minutes' => (int) env('LEADERBOARD_REFRESH_MINUTES', 15),
    'top_count' => (int) env('LEADERBOARD_TOP_COUNT', 5),
];
