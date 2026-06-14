<?php

namespace App\Services\Lifecycle;

class NullLifecycleNotifier implements LifecycleNotifier
{
    public function publishBirth(array $payload): void {}

    public function publishEulogy(array $payload): void {}
}
