<?php

namespace App\Services\Geo;

/**
 * PURE. Maps a Chernarus coordinate to the nearest named town/POI within a radius, returning ONLY
 * the label — never the coordinate. Used exclusively to build aggregate `region => count` trends for
 * the newspaper; a coordinate must never leave this layer attached to a player. Returns null for the
 * deep wilderness (no nearby POI), which keeps "middle of nowhere" deaths out of town trend counts.
 */
class ChernarusRegions
{
    private const RADIUS_M = 1500.0;

    /** label => [x, y] approximate town centers on the 15360x15360 Chernarus map. */
    private const POIS = [
        'Chernogorsk' => [6700, 2500],
        'Elektrozavodsk' => [10400, 2300],
        'Berezino' => [12900, 9500],
        'Severograd' => [7900, 12500],
        'Northwest Airfield' => [4500, 10200],
        'Zelenogorsk' => [2700, 5300],
        'Novodmitrovsk' => [11900, 12700],
        'Gorka' => [9500, 8800],
        'Stary Sobor' => [6100, 7700],
        'Vybor' => [3800, 8900],
    ];

    public static function regionFor(?float $x, ?float $y): ?string
    {
        if ($x === null || $y === null) {
            return null;
        }

        $best = null;
        $bestDist = self::RADIUS_M;
        foreach (self::POIS as $label => [$px, $py]) {
            $d = sqrt(($x - $px) ** 2 + ($y - $py) ** 2);
            if ($d <= $bestDist) {
                $bestDist = $d;
                $best = $label;
            }
        }

        return $best;
    }
}
