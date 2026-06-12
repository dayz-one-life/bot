<?php

namespace App\Services\Adm;

use App\Models\AdmFile;
use App\Services\Life\LifeTracker;
use App\Services\Nitrado\NitradoClient;
use App\Services\State\BotState;

class AdmIngestor
{
    private PositionRecorder $positions;

    public function __construct(
        private AdmParser $parser,
        private LifeTracker $tracker,
        ?PositionRecorder $positions = null,
    ) {
        $this->positions = $positions ?? new PositionRecorder();
    }

    /**
     * One ingestion tick. Processes the newest file every tick; drains up to
     * $backfillBudget older incomplete files (oldest-first). Flips BACKFILL->LIVE
     * once every file is complete or cursor-current.
     */
    public function tick(NitradoClient $client, BotState $state, int $backfillBudget = 15): void
    {
        $files = $client->listAdmFiles(); // oldest-first
        if (empty($files)) return;

        $offsetMs = $this->parser->deriveClockOffsetMs($files);
        $newestPath = $files[count($files) - 1]['path'];
        $budget = $backfillBudget;
        $allCaughtUp = true;
        $isLive = $state->get('mode', 'backfill') === 'live';

        foreach ($files as $file) {
            $row = AdmFile::where('path', $file['path'])->first();
            $isNewest = $file['path'] === $newestPath;

            if ($row?->is_complete && !$isNewest) continue;
            if (!$isNewest) {
                if ($budget <= 0) { $allCaughtUp = false; continue; }
                $budget--;
            } elseif (!$isLive && !$allCaughtUp) {
                // Backfill: don't jump ahead to the newest (live) file while older
                // files are still pending — the state machine needs events in order.
                // The newest file is last in oldest-first order, so it is processed
                // once all older files are complete (allCaughtUp still true here).
                continue;
            }

            try {
                $content = $client->downloadFile($file['path']);
            } catch (\Throwable $e) {
                $allCaughtUp = false;
                continue;
            }

            $cursor = $row?->last_processed_line ?? 0;
            $fallback = $file['timestamp'] ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $newCursor = $this->processFile($content, $cursor, $fallback, $offsetMs);

            AdmFile::updateOrCreate(
                ['path' => $file['path']],
                [
                    'name' => $file['name'],
                    'log_date' => $file['timestamp'],
                    'last_processed_line' => $newCursor,
                    'is_complete' => !$isNewest,
                ]
            );
        }

        if ($allCaughtUp && $state->get('mode', 'backfill') !== 'live') {
            $state->set('mode', 'live');
            $state->set('go_live_at', (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'));
        }
    }

    /**
     * Apply events from a file's content, starting at $cursor (0-based line index).
     * $offsetMs converts server-local log time to UTC. Returns the new cursor (line count).
     */
    public function processFile(string $content, int $cursor, \DateTimeImmutable $fallbackDate, int $offsetMs): int
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $total = count($lines);
        if ($cursor < 0) $cursor = 0;
        if ($cursor > $total) $cursor = $total; // file shrank/rotated: don't reprocess applied lines

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

            if ($c = $this->parser->parseConnect($raw)) { $this->tracker->connect($c['gamertag'], $ts); }
            elseif ($d = $this->parser->parseDisconnect($raw)) { $this->tracker->disconnect($d['gamertag'], $ts); }
            elseif ($k = $this->parser->parseDeath($raw)) { $this->tracker->death($k, $ts); }

            if (($pos = $this->parser->parsePosition($raw)) !== null) {
                $this->positions->record($pos['gamertag'], $pos['x'], $pos['y'], $ts);
            }
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
