<?php

namespace App\Services\Online;

class NullOnlineRosterNotifier implements OnlineRosterNotifier
{
    /** @var array{title:string, description:string}|null */
    public ?array $lastPayload = null;

    public function publish(array $payload): void
    {
        $this->lastPayload = $payload;
    }
}
