<?php

namespace App\Services\Adm;

use App\Services\Life\LifeTracker;

class AdmIngestor
{
    public function __construct(
        private AdmParser $parser,
        private LifeTracker $tracker,
    ) {}

    /**
     * Apply events from a file's content, starting at $cursor (0-based line index).
     * $offsetMs converts server-local log time to UTC. Returns the new cursor (line count).
     */
    public function processFile(string $content, int $cursor, \DateTimeImmutable $fallbackDate, int $offsetMs): int
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $total = count($lines);
        if ($cursor < 0 || $cursor > $total) $cursor = 0;

        // Rebuild timestamp context from the whole file (cheap; files are KB).
        $tsByLine = $this->parser->assignTimestamps($lines, $fallbackDate);

        for ($i = 0; $i < $total; $i++) {
            if ($i < $cursor) continue;
            $raw = $lines[$i];
            if ($raw === '' || $raw === null) continue;

            // Boot header: a reboot. Use the header's own time (offset-adjusted).
            if (($boot = $this->parser->parseBoot($raw)) !== null) {
                $this->tracker->reboot($this->utc($boot, $offsetMs));
                continue;
            }

            $localTs = $tsByLine[$i];
            if ($localTs === null) continue;
            $ts = $this->fromMs($localTs + $offsetMs);

            if ($c = $this->parser->parseConnect($raw)) { $this->tracker->connect($c['gamertag'], $ts); continue; }
            if ($d = $this->parser->parseDisconnect($raw)) { $this->tracker->disconnect($d['gamertag'], $ts); continue; }
            if ($k = $this->parser->parseDeath($raw)) { $this->tracker->death($k, $ts); continue; }
        }

        return $total;
    }

    private function fromMs(int $ms): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@'.intdiv($ms, 1000)))->setTimezone(new \DateTimeZone('UTC'));
    }

    private function utc(string $localDateTime, int $offsetMs): \DateTimeImmutable
    {
        $base = new \DateTimeImmutable($localDateTime, new \DateTimeZone('UTC'));
        return $this->fromMs($base->getTimestamp() * 1000 + $offsetMs);
    }
}
