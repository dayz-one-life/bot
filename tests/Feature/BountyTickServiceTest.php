<?php

use App\Services\BountyTickService;
use Laracord\Services\Service;

it('is a discoverable Laracord service constructible without a bot', function () {
    $svc = new BountyTickService();
    expect($svc)->toBeInstanceOf(Service::class);
});
