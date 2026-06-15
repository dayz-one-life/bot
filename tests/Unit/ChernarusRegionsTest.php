<?php

use App\Services\Geo\ChernarusRegions;

it('maps a coordinate near a town to that town', function () {
    expect(ChernarusRegions::regionFor(6700.0, 2500.0))->toBe('Chernogorsk');
    expect(ChernarusRegions::regionFor(10400.0, 2300.0))->toBe('Elektrozavodsk');
});

it('returns null for deep wilderness', function () {
    expect(ChernarusRegions::regionFor(0.0, 0.0))->toBeNull();
});

it('tolerates null coordinates', function () {
    expect(ChernarusRegions::regionFor(null, null))->toBeNull();
});
