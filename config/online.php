<?php

return [
    'enabled' => filter_var(env('CONNECTIONS_ENABLED', true), FILTER_VALIDATE_BOOL),
    // No fallback: unset/empty channel => null => the notifier no-ops (feature off).
    'channel_id' => env('CONNECTIONS_CHANNEL_ID') ?: null,
    'refresh_minutes' => (int) env('CONNECTIONS_REFRESH_MINUTES', 5),
];
