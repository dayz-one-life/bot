<?php

namespace App\Services\Connection;

interface ConnectionNotifier
{
    public function connected(string $gamertag, \DateTimeImmutable $ts): void;

    public function disconnected(string $gamertag, \DateTimeImmutable $ts, ?int $sessionSeconds): void;
}
