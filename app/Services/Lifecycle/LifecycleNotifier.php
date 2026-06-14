<?php

namespace App\Services\Lifecycle;

/**
 * Posts a birth or eulogy. Payload shape:
 *   ['ping' => ?string, 'title' => string, 'description' => string,
 *    'fields' => array<int,array{name:string,value:string}>, 'color' => int, 'footer' => string]
 * `ping` is a plain-content line carrying a real <@id> mention (or null when unlinked) — Discord
 * does NOT notify on mentions inside an embed, so the ping must ride on the message content.
 */
interface LifecycleNotifier
{
    public function publishBirth(array $payload): void;

    public function publishEulogy(array $payload): void;
}
