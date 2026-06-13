<?php

namespace App\Services\Connection;

class NullConnectionNotifier implements ConnectionNotifier
{
    public function connected(string $gamertag, \DateTimeImmutable $ts): void {}

    public function disconnected(string $gamertag, \DateTimeImmutable $ts, ?int $sessionSeconds): void {}
}
