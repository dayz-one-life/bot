<?php

return [
    'enabled' => filter_var(env('LIFECYCLE_ENABLED', true), FILTER_VALIDATE_BOOL),

    // A life "counts" (birth announced, death eulogized) once it accrues this much playtime.
    'grace_minutes' => (int) env('LIFE_GRACE_MINUTES', 5),

    // Only ban a death if the life had at least this much playtime.
    'ban_min_playtime_minutes' => (int) env('BAN_MIN_PLAYTIME_MINUTES', 60),

    // Suppress post-downtime backlog: only announce births/eulogies for recent events.
    'max_age_minutes' => (int) env('LIFECYCLE_MAX_AGE_MINUTES', 30),

    // No fallback: unset/empty channel => null => the notifier no-ops for that feed.
    'births_channel_id' => env('BIRTHS_CHANNEL_ID') ?: null,
    'eulogy_channel_id' => env('EULOGY_CHANNEL_ID') ?: null,

    // How often the announce service scans (seconds floor 60).
    'refresh_minutes' => (int) env('LIFECYCLE_REFRESH_MINUTES', 1),
];
