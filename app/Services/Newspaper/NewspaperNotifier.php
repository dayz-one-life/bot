<?php

namespace App\Services\Newspaper;

interface NewspaperNotifier
{
    /** @param array<int,array<string,mixed>> $embeds ordered embed payloads (masthead first) */
    public function publish(array $embeds): void;
}
