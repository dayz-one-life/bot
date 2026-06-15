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

        // Empty categories (zero counts / absent superlatives) are omitted rather than printed as
        // "0" or "—", so a quiet week's box stays tidy. Playtime is always shown as the scene-setter.
        $lines = ["Total playtime ..... {$c['playtime_human']}"];

        if ((int) $c['lives_lost'] > 0) {
            array_unshift($lines, "Lives lost ......... **{$c['lives_lost']}** {$deltaLives}");
        }
        if ((int) $c['infected_attacks'] > 0) {
            $lines[] = "Infected attacks ... **{$c['infected_attacks']}** {$deltaInf}";
        }
        if ((int) $c['bunker_descents'] > 0) {
            $lines[] = "Bunker descents .... {$c['bunker_descents']}";
        }
        if ((int) $c['souls_alive'] > 0) {
            $lines[] = "Souls still alive .. {$c['souls_alive']}";
        }
        if ($s['deadliest_player']) {
            $lines[] = "Deadliest player ... `{$s['deadliest_player']['gamertag']}` ({$s['deadliest_player']['kills']})";
        }
        if ($s['furthest_kill']) {
            $lines[] = 'Furthest kill ...... '.round($s['furthest_kill']['distance']).'m';
        }
        if ($s['longest_life_ended']) {
            $lines[] = "Longest life ended . `{$s['longest_life_ended']['gamertag']}` {$s['longest_life_ended']['duration_human']}";
        }
        if ($s['most_travelled']) {
            $lines[] = "Most travelled ..... `{$s['most_travelled']['gamertag']}` {$s['most_travelled']['km']}km";
        }

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
