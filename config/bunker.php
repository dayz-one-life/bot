<?php

return [
    'enabled'          => filter_var(env('BUNKER_TRACKING_ENABLED', true), FILTER_VALIDATE_BOOL),
    'cooldown_minutes' => (int) env('BUNKER_VISIT_COOLDOWN_MINUTES', 60),
];
