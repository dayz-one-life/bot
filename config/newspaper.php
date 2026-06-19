<?php

return [
    'enabled' => filter_var(env('NEWSPAPER_ENABLED', true), FILTER_VALIDATE_BOOL),
    'channel_id' => env('NEWSPAPER_CHANNEL_ID') ?: null,
    // ISO day-of-week (1=Mon..7=Sun) and UTC hour of the weekly publish moment. Default Fri 22:00 UTC = 6pm UTC-4.
    'publish_dow' => (int) env('NEWSPAPER_PUBLISH_DOW', 5),
    'publish_hour_utc' => (int) env('NEWSPAPER_PUBLISH_HOUR_UTC', 22),
    // The issue is THREE 120-250-word sections in one LLM call — far longer than the eulogy/bounty
    // generators — so it gets its own, larger output cap to avoid truncating the (last) classifieds.
    'max_tokens' => (int) env('NEWSPAPER_MAX_TOKENS', 2000),
];
