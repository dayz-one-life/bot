<?php

use App\Services\Geo\ChernarusRegions;

it('maps a coordinate near a town to that town', function () {
    expect(ChernarusRegions::regionFor(6700.0, 2500.0))->toBe('Chernogorsk');
    expect(ChernarusRegions::regionFor(10400.0, 2300.0))->toBe('Elektrozavodsk');
});

it('returns null for deep wilderness', function () {
    expect(ChernarusRegions::regionFor(0.0, 0.0))->toBeNull();
});

it('returns a label for a coordinate just inside the radius', function () {
    // 1499 m due east of Chernogorsk center (6700, 2500) — within the 1500 m radius.
    expect(ChernarusRegions::regionFor(8199.0, 2500.0))->toBe('Chernogorsk');
});

it('returns null for a coordinate just outside the radius', function () {
    // 1501 m due east of Chernogorsk center — beyond the 1500 m radius, no POI in range.
    expect(ChernarusRegions::regionFor(8201.0, 2500.0))->toBeNull();
});

it('tolerates null coordinates', function () {
    expect(ChernarusRegions::regionFor(null, null))->toBeNull();
});
