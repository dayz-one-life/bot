<?php

namespace App\Services\Adm;

class AdmParser
{
    private const CONNECT_RE = '/Player "([^"]+)"\s*\(id=([^\s)]+)[^)]*\) is connected/u';
    private const DISCONNECT_RE = '/Player "([^"]+)"\s*\(id=([^\s)]+)[^)]*\) has been disconnected/u';
    private const KILL_RE = '/Player "([^"]+)" \(DEAD\) \(id=([^\s)]+)[^)]*\) killed by Player "([^"]+)" \(id=([^\s)]+)[^)]*\)(.*)$/u';
    private const WEAPON_RE = '/with (.+?)(?: from ([\d.]+) meters)?\s*$/u';
    private const DEATH_RE = '/Player "([^"]+)" \(DEAD\) \(id=([^\s)]+)[^)]*\)(.*)$/u';

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
}
