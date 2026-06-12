<?php

return [
    'activity_window_hours' => (int) env('BOUNTY_ACTIVITY_WINDOW_HOURS', 48),
    'min_playtime_hours'    => (int) env('BOUNTY_MIN_PLAYTIME_HOURS', 2),
    'move_margin_min'       => (int) env('BOUNTY_MOVE_MARGIN_MIN', 5),

    'assoc_window_days'     => (int) env('BOUNTY_ASSOC_WINDOW_DAYS', 14),
    'position_retention_days' => (int) env('BOUNTY_POSITION_RETENTION_DAYS', 0), // 0 = keep forever (no pruning)
    'assoc_radius_m'        => (float) env('BOUNTY_ASSOC_RADIUS_M', 150),
    'assoc_threshold'       => (float) env('BOUNTY_ASSOC_THRESHOLD', 0.45),
    'weight_prox'           => (float) env('BOUNTY_ASSOC_WEIGHT_PROX', 0.55),
    'weight_copres'         => (float) env('BOUNTY_ASSOC_WEIGHT_COPRES', 0.35),
    'weight_killg'          => (float) env('BOUNTY_ASSOC_WEIGHT_KILLG', 0.10),
    'sync_window_min'       => (int) env('BOUNTY_SYNC_WINDOW_MIN', 3),

    'token_reward'          => (int) env('BOUNTY_TOKEN_REWARD', 1),
    'channel_id'            => env('BOUNTY_CHANNEL_ID') ?: env('BANS_CHANNEL_ID'),
];
