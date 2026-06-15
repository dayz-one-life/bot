<?php

namespace App\Services\Adm;

use App\Services\Hit\HitEventService;
use App\Services\Nitrado\NitradoClient;
use Carbon\CarbonImmutable;

/**
 * Replays ADM history through HitEventService to capture past hit events.
 * Reconstructs per-line UTC timestamps exactly as AdmIngestor. Idempotent: HitEventService::record
 * skips a hit identical to one already stored, so re-running this backfill — or running it over a
 * window live ingest already covered — does NOT duplicate hits. Does NOT touch lives/sessions.
 */
class HitBackfillService
{
    public function __construct(private AdmParser $parser) {}

    /**
     * @param  ?callable  $progress  fn(string $fileName, int $count): void
     * @return array{files:int, hits:int}
     */
    public function backfillAll(NitradoClient $client, HitEventService $hits, ?int $sinceDays = null, ?callable $progress = null): array
    {
        $files = $client->listAdmFiles(); // oldest-first
        if (empty($files)) return ['files' => 0, 'hits' => 0];

        $offsetMs = $this->parser->deriveClockOffsetMs($files);

        if ($sinceDays !== null) {
            $cut = CarbonImmutable::now()->subDays($sinceDays)->getTimestamp();
            $files = array_values(array_filter($files, function ($f) use ($cut) {
                $ts = $f['timestamp'] ?? null;
                return $ts instanceof \DateTimeInterface ? $ts->getTimestamp() >= $cut : true;
            }));
        }

        $total = 0;
        $fileCount = 0;
        foreach ($files as $f) {
            try {
                $content = $client->downloadFile($f['path']);
            } catch (\Throwable) {
                continue;
            }
            $fallback = ($f['timestamp'] ?? null) instanceof \DateTimeImmutable
                ? $f['timestamp']
                : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            $n = $this->backfillFile($content, $fallback, $offsetMs, $hits);
            $total += $n;
            $fileCount++;
            if ($progress) $progress($f['name'], $n);
        }

        return ['files' => $fileCount, 'hits' => $total];
    }

    /** Parse one file's hit lines and record each event. Returns the count recorded. */
    public function backfillFile(string $content, \DateTimeImmutable $fallback, int $offsetMs, HitEventService $hits): int
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $tsByLine = $this->parser->assignTimestamps($lines, $fallback);

        $count = 0;
        foreach ($lines as $i => $raw) {
            if ($raw === '' || $raw === null) continue;
            $localMs = $tsByLine[$i] ?? null;
            if ($localMs === null) continue;

            $hit = $this->parser->parseHit($raw);
            if ($hit === null) continue;

            $utc = (new \DateTimeImmutable('@'.intdiv($localMs + $offsetMs, 1000)))
                ->setTimezone(new \DateTimeZone('UTC'));

            if ($hits->record($hit, $utc) !== null) {
                $count++;
            }
        }

        return $count;
    }
}
