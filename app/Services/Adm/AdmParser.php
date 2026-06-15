<?php

namespace App\Services\Adm;

class AdmParser
{
    private const CONNECT_RE = '/Player "([^"]+)"\s*\(id=([^\s)]+)[^)]*\) is connected/u';
    private const DISCONNECT_RE = '/Player "([^"]+)"\s*\(id=([^\s)]+)[^)]*\) has been disconnected/u';
    private const KILL_RE = '/Player "([^"]+)" \(DEAD\) \(id=([^\s)]+)[^)]*\) killed by Player "([^"]+)" \(id=([^\s)]+)[^)]*\)(.*)$/u';
    private const WEAPON_RE = '/with (.+?)(?: from ([\d.]+) meters)?\s*$/u';
    private const DEATH_RE = '/Player "([^"]+)" \(DEAD\) \(id=([^\s)]+)[^)]*\)(.*)$/u';
    private const HEADER_RE = '/AdminLog started on (\d{4})-(\d{2})-(\d{2}) at (\d{2}):(\d{2}):(\d{2})/';
    private const TIME_RE = '/^(\d{2}):(\d{2}):(\d{2})/';
    private const PLAYER_NAME_RE = '/Player "([^"]+)"/u';
    private const POSITION_RE = '/pos=<\s*(-?[\d.]+),\s*(-?[\d.]+),\s*(-?[\d.]+)\s*>/u';

    // Chernarus map bounds (with a ~1km margin) used to reject DayZ's off-map "unknown position" sentinel.
    private const MAP_MIN = -1000.0;
    private const MAP_MAX = 16360.0;
    private const BUNKER_ENTRANCE_RE = '/Player "([^"]+)".*was teleported.*RestrictedAreaBunkerEntrance$/u';

    private const DAY_MS = 86400000;
    private const ROLLOVER_THRESHOLD_SEC = 43200; // 12h
    private const FIFTEEN_MIN_MS = 900000;

    public function parseConnect(string $raw): ?array
    {
        if (!preg_match(self::CONNECT_RE, $raw, $m)) return null;
        return ['gamertag' => $m[1], 'id' => $m[2]];
    }

    public function parseDisconnect(string $raw): ?array
    {
        if (!preg_match(self::DISCONNECT_RE, $raw, $m)) return null;
        return ['gamertag' => $m[1], 'id' => $m[2]];
    }

    /**
     * A bunker visit: the player was teleported into the bunker's restricted area on
     * spawn-in. Self-labeling via the reason string — coordinate-independent. Returns
     * the gamertag, or null for the exit line / any other line.
     */
    public function parseBunkerEntrance(string $raw): ?array
    {
        if (!preg_match(self::BUNKER_ENTRANCE_RE, $raw, $m)) return null;
        return ['gamertag' => $m[1]];
    }

    /**
     * Detect any death. Returns victim/id/cause and (for PvP) killer/weapon/distance.
     * "hit by" lines are damage events, never the death record, so they are ignored.
     */
    public function parseDeath(string $raw): ?array
    {
        if (str_contains($raw, 'hit by')) return null;
        if (!preg_match(self::DEATH_RE, $raw, $m)) return null;

        $victim = $m[1];
        $id = $m[2];
        $tail = $m[3];

        if (preg_match(self::KILL_RE, $raw, $k)) {
            $weapon = null;
            $distance = null;
            if (preg_match(self::WEAPON_RE, $k[5], $w)) {
                $weapon = trim($w[1]);
                $distance = (isset($w[2]) && $w[2] !== '') ? (float) $w[2] : null;
            }
            return [
                'victim' => $k[1], 'id' => $k[2], 'cause' => 'pvp',
                'killer' => $k[3], 'weapon' => $weapon, 'distance' => $distance,
            ];
        }

        $t = strtolower($tail);
        $cause = match (true) {
            str_contains($t, 'bled out') => 'bled_out',
            str_contains($t, 'drowned') => 'drowned',
            str_contains($t, 'committed suicide') => 'suicide',
            str_contains($t, 'killed by') => 'environment',
            str_contains($t, 'died') => 'died',
            default => 'unknown',
        };

        return ['victim' => $victim, 'id' => $id, 'cause' => $cause, 'killer' => null, 'weapon' => null, 'distance' => null];
    }

    /** Returns 'Y-m-d H:i:s' for a boot header line, else null. */
    public function parseBoot(string $raw): ?string
    {
        if (!preg_match(self::HEADER_RE, $raw, $m)) return null;
        return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
    }

    /**
     * Per-line epoch-ms timestamps. Null for header/blank/non-event lines.
     * @param string[] $lines
     */
    public function assignTimestamps(array $lines, \DateTimeImmutable $fallbackDate): array
    {
        $out = array_fill(0, count($lines), null);
        $dayStart = null; // epoch ms at UTC midnight of the current log date
        $lastSec = -1;

        // Read the fallback date in UTC: dates are reconstructed against UTC
        // midnight (gmmktime), so a non-UTC $fallbackDate must be normalized
        // first or it could select the wrong calendar day.
        $fallbackDate = $fallbackDate->setTimezone(new \DateTimeZone('UTC'));

        foreach ($lines as $i => $raw) {
            if ($raw === '' || $raw === null) continue;

            if (preg_match(self::HEADER_RE, $raw, $h)) {
                $dayStart = gmmktime(0, 0, 0, (int) $h[2], (int) $h[3], (int) $h[1]) * 1000;
                $lastSec = (int) $h[4] * 3600 + (int) $h[5] * 60 + (int) $h[6];
                continue;
            }

            if (!preg_match(self::TIME_RE, $raw, $t)) continue;
            $sec = (int) $t[1] * 3600 + (int) $t[2] * 60 + (int) $t[3];

            if ($dayStart === null) {
                $dayStart = gmmktime(0, 0, 0,
                    (int) $fallbackDate->format('n'),
                    (int) $fallbackDate->format('j'),
                    (int) $fallbackDate->format('Y')) * 1000;
            } elseif ($lastSec - $sec > self::ROLLOVER_THRESHOLD_SEC) {
                $dayStart += self::DAY_MS;
            }
            $lastSec = $sec;
            $out[$i] = $dayStart + $sec * 1000;
        }

        return $out;
    }

    /**
     * Harvest a horizontal position sample (x, y) from any line that names a
     * player and carries a pos=<x, y, z> token, wherever it appears on the line.
     * z (altitude) is dropped — proximity is judged on the horizontal plane.
     *
     * Verified against live ADM (2026-06-12): each player's pos sits inside their own
     * `(id=<hash> pos=<x, y, z>)` parentheses, present on connect lines, periodic
     * snapshot lines, build/place events, and deaths. When a line names multiple
     * players (e.g. a kill line), the FIRST-named player and FIRST pos token are
     * recorded — these belong to the same player.
     *
     * "hit by" lines are transient combat-damage events, not position snapshots; they
     * are ignored so proximity reflects where players actually dwell, not firefights.
     */
    public function parsePosition(string $raw): ?array
    {
        if (str_contains($raw, 'hit by')) return null;
        if (!preg_match(self::PLAYER_NAME_RE, $raw, $p)) return null;
        if (!preg_match(self::POSITION_RE, $raw, $c)) return null;
        $x = (float) $c[1];
        $y = (float) $c[2];
        // DayZ logs ~ -FLT_MAX (a 39-digit decimal) for an unknown position. Fed into distance
        // math that sentinel yields astronomically wrong travel totals, so drop off-map samples.
        if (!$this->inMapBounds($x, $y)) return null;
        return ['gamertag' => $p[1], 'x' => $x, 'y' => $y];
    }

    /** Chernarus is 15360x15360; a small margin tolerates legit edge/just-off-map coordinates. */
    private function inMapBounds(float $x, float $y): bool
    {
        return $x >= self::MAP_MIN && $x <= self::MAP_MAX
            && $y >= self::MAP_MIN && $y <= self::MAP_MAX;
    }

    /**
     * Parse an ADM "hit by" damage line into a structured hit event. Player attackers yield
     * attacker_type='player' + attacker_gamertag; infected/animal/other sources yield a humanized
     * attacker_label and a classified attacker_type, with attacker_gamertag null. Coordinates are
     * the VICTIM's (used only to derive an aggregate region downstream — never exposed raw).
     *
     * @return array{victim:string,victim_hp:?int,victim_x:?float,victim_y:?float,body_part:?string,attacker_gamertag:?string,attacker_type:string,attacker_label:?string}|null
     */
    public function parseHit(string $raw): ?array
    {
        $pos = strpos($raw, 'hit by');
        if ($pos === false) return null;
        if (!preg_match(self::PLAYER_NAME_RE, $raw, $vm)) return null;

        $before = substr($raw, 0, $pos);
        $after = substr($raw, $pos + strlen('hit by'));

        $hp = null;
        if (preg_match('/\[HP:\s*(-?\d+)\]/u', $raw, $hm)) $hp = (int) $hm[1];

        $vx = $vy = null;
        if (preg_match(self::POSITION_RE, $before, $pm)) {
            $vx = (float) $pm[1];
            $vy = (float) $pm[2];
        }

        $bodyPart = null;
        if (preg_match('/into ([A-Za-z]+)\s*$/u', trim($after), $bm)) $bodyPart = $bm[1];

        // Player attacker?
        if (preg_match('/^\s*Player "([^"]+)"/u', $after, $am)) {
            return [
                'victim' => $vm[1], 'victim_hp' => $hp, 'victim_x' => $vx, 'victim_y' => $vy,
                'body_part' => $bodyPart,
                'attacker_gamertag' => $am[1], 'attacker_type' => 'player', 'attacker_label' => null,
            ];
        }

        // Non-player source: strip the trailing "into <BodyPart>" and any "(...)" detail.
        $src = trim($after);
        $src = preg_replace('/\s+into\s+[A-Za-z]+\s*$/u', '', $src);
        $src = trim(preg_replace('/\(.*$/', '', $src));

        $type = match (true) {
            // Kill lines name infected as a ZmbM_* class; hit lines name them as the word "Infected".
            str_contains($src, 'Zmb'), str_contains($src, 'Infected') => 'infected',
            str_starts_with($src, 'Animal_') => 'animal',
            default => 'environment',
        };
        $label = $type === 'environment'
            ? $this->humanizeEnvironment($src)
            : DayzNameHumanizer::text($src);

        return [
            'victim' => $vm[1], 'victim_hp' => $hp, 'victim_x' => $vx, 'victim_y' => $vy,
            'body_part' => $bodyPart,
            'attacker_gamertag' => null, 'attacker_type' => $type, 'attacker_label' => $label,
        ];
    }

    private function humanizeEnvironment(string $src): string
    {
        return match (true) {
            stripos($src, 'Fall') !== false => 'a fall',
            stripos($src, 'Explosion') !== false => 'an explosion',
            $src === '' => 'the environment',
            default => $src,
        };
    }

    /**
     * Server-local log clock -> UTC offset in ms (add to a log ts to get UTC).
     * @param array<int,array{timestamp:\DateTimeImmutable,modifiedAt:?int}> $files
     */
    public function deriveClockOffsetMs(array $files): int
    {
        $best = null;
        foreach ($files as $f) {
            if (!($f['timestamp'] instanceof \DateTimeImmutable) || !is_int($f['modifiedAt'] ?? null)) continue;
            $candidate = $f['modifiedAt'] * 1000 - (int) ($f['timestamp']->getTimestamp() * 1000);
            if ($best === null || $candidate < $best) $best = $candidate;
        }
        if ($best === null) return 0;
        return (int) (round($best / self::FIFTEEN_MIN_MS) * self::FIFTEEN_MIN_MS);
    }
}
