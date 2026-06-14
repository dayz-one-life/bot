<?php

namespace App\Services\Adm;

use App\Services\Bunker\BunkerVisitService;
use App\Services\Nitrado\NitradoClient;
use Carbon\CarbonImmutable;

/**
 * Replays ADM history through BunkerVisitService to capture past bunker visits.
 * Reconstructs per-line UTC timestamps exactly as AdmIngestor. Idempotent on re-run
 * (the service's cooldown window swallows re-derived duplicates). Does NOT touch
 * lives/sessions — it relies on lives already reconstructed by normal ingest, and
 * associates each visit to the life whose window contains it.
 */
class BunkerVisitBackfillService
{
    public function __construct(private AdmParser $parser) {}

    /**
     * @param  ?callable  $progress  fn(string $fileName, int $count): void
     * @return array{files:int, visits:int}
     */
    public function backfillAll(NitradoClient $client, BunkerVisitService $visits, ?int $sinceDays = null, ?callable $progress = null): array
    {
        $files = $client->listAdmFiles(); // oldest-first
        if (empty($files)) return ['files' => 0, 'visits' => 0];

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

            $n = $this->backfillFile($content, $fallback, $offsetMs, $visits);
            $total += $n;
            $fileCount++;
            if ($progress) $progress($f['name'], $n);
        }

        return ['files' => $fileCount, 'visits' => $total];
    }

    /** Parse one file's entrance lines and record each visit. Returns the count recorded. */
    public function backfillFile(string $content, \DateTimeImmutable $fallback, int $offsetMs, BunkerVisitService $visits): int
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $tsByLine = $this->parser->assignTimestamps($lines, $fallback);

        $count = 0;
        foreach ($lines as $i => $raw) {
            if ($raw === '' || $raw === null) continue;
            $localMs = $tsByLine[$i] ?? null;
            if ($localMs === null) continue;

            $b = $this->parser->parseBunkerEntrance($raw);
            if ($b === null) continue;

            $utc = (new \DateTimeImmutable('@'.intdiv($localMs + $offsetMs, 1000)))
                ->setTimezone(new \DateTimeZone('UTC'));

            if ($visits->record($b['gamertag'], $utc) !== null) {
                $count++;
            }
        }

        return $count;
    }
}
