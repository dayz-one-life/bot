<?php

namespace App\Services\Adm;

use App\Models\Player;
use App\Models\PlayerPosition;
use App\Services\Nitrado\NitradoClient;
use Carbon\CarbonImmutable;

class PositionBackfillService
{
    public function __construct(private AdmParser $parser) {}

    /**
     * Re-read every ADM file (optionally only those newer than $sinceDays) and insert
     * position samples. Reconstructs per-line UTC timestamps exactly as AdmIngestor.
     * Does NOT touch lives/sessions/deaths. When $fresh, truncates player_positions first.
     *
     * @param  ?callable  $progress  fn(string $fileName, int $rowCount): void
     * @return array{files:int, positions:int}
     */
    public function backfillAll(NitradoClient $client, ?int $sinceDays = null, bool $fresh = true, ?callable $progress = null): array
    {
        $files = $client->listAdmFiles(); // oldest-first
        if (empty($files)) return ['files' => 0, 'positions' => 0];

        $offsetMs = $this->parser->deriveClockOffsetMs($files);

        if ($sinceDays !== null) {
            $cut = CarbonImmutable::now()->subDays($sinceDays)->getTimestamp();
            $files = array_values(array_filter($files, function ($f) use ($cut) {
                $ts = $f['timestamp'] ?? null;
                return $ts instanceof \DateTimeInterface ? $ts->getTimestamp() >= $cut : true;
            }));
        }

        if ($fresh) {
            PlayerPosition::truncate();
        }

        $map = Player::pluck('id', 'gamertag')->all();
        $totalRows = 0;
        $fileCount = 0;

        foreach ($files as $f) {
            try {
                $content = $client->downloadFile($f['path']);
            } catch (\Throwable) {
                continue; // skip unreadable file, keep going
            }
            $fallback = ($f['timestamp'] ?? null) instanceof \DateTimeImmutable
                ? $f['timestamp']
                : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            $rows = $this->extractPositions($content, $fallback, $offsetMs, $map);
            $this->insertRows($rows);

            $totalRows += count($rows);
            $fileCount++;
            if ($progress) $progress($f['name'], count($rows));
        }

        return ['files' => $fileCount, 'positions' => $totalRows];
    }

    /**
     * Pure: parse positions out of one file's content into insertable rows.
     * Skips lines whose gamertag isn't in $gamertagToId and lines parsePosition rejects
     * (incl. "hit by"). recorded_at is a UTC 'Y-m-d H:i:s' string.
     *
     * @param  array<string,int>  $gamertagToId
     * @return array<int,array{player_id:int,x:float,y:float,recorded_at:string}>
     */
    public function extractPositions(string $content, \DateTimeImmutable $fallback, int $offsetMs, array $gamertagToId): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $tsByLine = $this->parser->assignTimestamps($lines, $fallback);

        $rows = [];
        foreach ($lines as $i => $raw) {
            if ($raw === '' || $raw === null) continue;
            $localMs = $tsByLine[$i] ?? null;
            if ($localMs === null) continue;

            $pos = $this->parser->parsePosition($raw);
            if ($pos === null) continue;

            $id = $gamertagToId[$pos['gamertag']] ?? null;
            if ($id === null) continue;

            $utc = (new \DateTimeImmutable('@'.intdiv($localMs + $offsetMs, 1000)))
                ->setTimezone(new \DateTimeZone('UTC'));
            $rows[] = [
                'player_id' => $id,
                'x' => $pos['x'],
                'y' => $pos['y'],
                'recorded_at' => $utc->format('Y-m-d H:i:s'),
            ];
        }
        return $rows;
    }

    /** Bulk-insert rows in chunks (keeps memory bounded on large files). */
    public function insertRows(array $rows): void
    {
        foreach (array_chunk($rows, 1000) as $chunk) {
            PlayerPosition::insert($chunk);
        }
    }
}
