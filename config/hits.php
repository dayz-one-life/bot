<?php

return [
    'enabled' => filter_var(env('HIT_TRACKING_ENABLED', true), FILTER_VALIDATE_BOOL),
];
