<?php

return [
    'enabled' => filter_var(env('NEWSPAPER_ENABLED', true), FILTER_VALIDATE_BOOL),
    'channel_id' => env('NEWSPAPER_CHANNEL_ID') ?: null,
    // ISO day-of-week (1=Mon..7=Sun) and UTC hour of the weekly publish moment. Default Fri 22:00 UTC = 6pm UTC-4.
    'publish_dow' => (int) env('NEWSPAPER_PUBLISH_DOW', 5),
    'publish_hour_utc' => (int) env('NEWSPAPER_PUBLISH_HOUR_UTC', 22),
];
