<?php

namespace App\Services\Online;

interface OnlineRosterNotifier
{
    /**
     * Publish (post or edit) the online roster.
     *
     * @param  array{title:string, description:string}  $payload
     */
    public function publish(array $payload): void;
}
