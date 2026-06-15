<?php

namespace App\Services\Newspaper;

class NullNewspaperNotifier implements NewspaperNotifier
{
    public function publish(array $embeds): void {}
}
