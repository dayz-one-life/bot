<?php

namespace App\Services\Newspaper;

use Carbon\CarbonImmutable;

/**
 * PURE: weekly facts + generated prose -> an ordered list of Discord-agnostic embed payloads
 * (masthead + Editorial + Week in Numbers + Recap + Classifieds). The "Week in Numbers" box is
 * built here from pure data. Plain backticked gamertags only — NEVER <@id> mentions.
 */
class NewspaperComposer
{
    private const COLOR = 0xC9B037; // newsprint gold

    /**
     * @param array<string,mixed> $facts
     * @param array{editorial:string,recap:string,classifieds:string} $prose
     * @return array<int,array<string,mixed>>
     */
    public function compose(array $facts, array $prose, int $issueNumber): array
    {
        return [
            $this->masthead($facts, $issueNumber),
            $this->section('✒️ EDITORIAL', $prose['editorial'] ?? ''),
            $this->numbers($facts),
            $this->section('🗞️ THE RECAP', $prose['recap'] ?? ''),
            $this->section('📋 CLASSIFIEDS', $prose['classifieds'] ?? ''),
        ];
    }

    private function masthead(array $facts, int $issueNumber): array
    {
        $start = CarbonImmutable::parse($facts['period']['start'])->format('M j');
        $end = CarbonImmutable::parse($facts['period']['end'])->format('M j');

        return [
            'title' => "📰 THE ONE LIFE TRIBUNE — No.{$issueNumber}",
            'description' => "*Week of {$start}–{$end}* · \"All the death that's fit to print\"",
            'color' => self::COLOR,
        ];
    }

    private function section(string $title, string $body): array
    {
        return [
            'title' => $title,
            'description' => $body === '' ? '*Slow news week.*' : $body,
            'color' => self::COLOR,
        ];
    }

    private function numbers(array $facts): array
    {
        $c = $facts['counts'];
        $s = $facts['superlatives'];

        $deltaLives = $this->delta((int) $c['lives_lost'], (int) ($c['lives_lost_prev'] ?? 0));
        $deltaInf = $this->delta((int) $c['infected_attacks'], (int) ($c['infected_attacks_prev'] ?? 0));

        $deadliest = $s['deadliest_player'] ? "`{$s['deadliest_player']['gamertag']}` ({$s['deadliest_player']['kills']})" : '—';
        $furthest = $s['furthest_kill'] ? round($s['furthest_kill']['distance']).'m' : '—';
        $longest = $s['longest_life_ended'] ? "`{$s['longest_life_ended']['gamertag']}` {$s['longest_life_ended']['duration_human']}" : '—';
        $travelled = $s['most_travelled'] ? "`{$s['most_travelled']['gamertag']}` {$s['most_travelled']['km']}km" : '—';

        $lines = [
            "Lives lost ......... **{$c['lives_lost']}** {$deltaLives}",
            "Total playtime ..... {$c['playtime_human']}",
            "Infected attacks ... **{$c['infected_attacks']}** {$deltaInf}",
            "Bunker descents .... {$c['bunker_descents']}",
            "Souls still alive .. {$c['souls_alive']}",
            "Deadliest player ... {$deadliest}",
            "Furthest kill ...... {$furthest}",
            "Longest life ended . {$longest}",
            "Most travelled ..... {$travelled}",
        ];

        return [
            'title' => '📊 THE WEEK IN NUMBERS',
            'description' => "```\n".implode("\n", $lines)."\n```",
            'color' => self::COLOR,
        ];
    }

    private function delta(int $now, int $prev): string
    {
        $d = $now - $prev;
        if ($d === 0) return '';
        return $d > 0 ? "(▲{$d})" : '(▼'.abs($d).')';
    }
}
