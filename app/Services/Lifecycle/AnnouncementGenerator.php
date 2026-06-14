<?php

namespace App\Services\Lifecycle;

use App\Services\Llm\OpenRouterClient;
use App\Services\Personality\MessagePicker;

/**
 * Builds the birth/eulogy prompt from structured facts (+ raw log), calls OpenRouter, and parses
 * the result into headline + body. Any failure (no key, timeout, non-2xx, empty) falls back to the
 * canned `birth.` / `eulogy.` personality pools. Placeholders {{PLAYER}}/{{KILLER}} are left intact for
 * the announcer to substitute.
 */
class AnnouncementGenerator
{
    private const SYSTEM = <<<'TXT'
You are the staff obituary and society columnist for "The One Life Tribune", a savage, witty
post-apocalyptic newspaper covering a hardcore DayZ "one life" server. Players get ONE life; when
they die they are banned for a while, so every death is a real funeral and every respawn is a
genuine rebirth.

Write a SUBSTANTIAL, newspaper-style piece — NOT a one-liner. Aim for 150-350 words across 2-4 short
paragraphs. Be funny, a little roasty, and creative. Use vivid Discord markdown formatting: a
dateline, **bold**, *italics*, the occasional `> blockquote` from a fictional witness, and plenty of
fitting emojis (🕯️💀🐻⚰️🎉👶📰).

Rules:
- Refer to the SUBJECT only as the literal token {{PLAYER}} and the KILLER (if any) only as {{KILLER}}.
  Never invent or alter these tokens; never write a real name in their place.
- Use ONLY the facts you are given. NEVER fabricate details that weren't provided — no invented
  weapons, distances, killers, ages, kill counts, OR previous lives/deaths. If a detail is absent
  (e.g. this is the player's first life, or no killer is given), do not mention or imply it exists.
- NEVER reveal map locations: no coordinates, grid references, or specific in-world place names for
  where anything happened. Generic atmosphere ("the coast", "the wasteland") is fine; an actual
  position is not.
- Output EXACTLY this shape: the FIRST line is a punchy ALL-CAPS tabloid HEADLINE (no markdown, no
  leading emoji required), then a blank line, then the article body. Do not label the sections.
TXT;

    public function __construct(
        private OpenRouterClient $client,
        private ?MessagePicker $picker = null,
    ) {}

    /**
     * @param 'birth'|'eulogy' $kind
     * @param array<string,mixed> $facts
     * @return array{headline:string,body:string}
     */
    public function generate(string $kind, array $facts): array
    {
        try {
            $raw = $this->client->complete(self::SYSTEM, $this->userPrompt($kind, $facts));
            return $this->split($raw);
        } catch (\Throwable) {
            return $this->fallback($kind, $facts);
        }
    }

    private function userPrompt(string $kind, array $facts): string
    {
        return $kind === 'birth' ? $this->birthPrompt($facts) : $this->eulogyPrompt($facts);
    }

    /**
     * A newborn life is always ~the grace window old, so we deliberately do NOT send its age (the
     * model would mistake it for a notable "5-minute life"). A first life sends no prior life at
     * all; a respawn sends only the real prior-life summary.
     *
     * @param array<string,mixed> $facts
     */
    private function birthPrompt(array $facts): string
    {
        $isFirst = ! empty($facts['is_first_life']);

        $payload = [
            'kind' => 'birth',
            'subject_placeholder' => '{{PLAYER}}',
            'is_first_life_ever' => $isFirst,
            'previous_life' => $isFirst ? null : $facts['prior_death'],
        ];

        $intro = $isFirst
            ? "Write a BIRTH ANNOUNCEMENT for a survivor spawning into the one-life server for the VERY FIRST TIME. They have NO previous life — do NOT invent a prior death, a past life, or how long they survived before. Celebrate and gently roast a brand-new arrival. Do NOT state how long the new life has been alive (they just spawned)."
            : "Write a BIRTH ANNOUNCEMENT for a survivor who just RESPAWNED after dying. You may reference 'previous_life' but invent nothing beyond it. Do NOT state how long the new life has been alive (they just spawned).";

        return $intro."\n\nDETAILS (JSON):\n".$this->json($payload);
    }

    /** @param array<string,mixed> $facts */
    private function eulogyPrompt(array $facts): string
    {
        $payload = [
            'kind' => 'eulogy',
            'subject_placeholder' => '{{PLAYER}}',
            'killer_placeholder' => $facts['killer'] ? '{{KILLER}}' : null,
            'facts' => [
                'cause_of_death' => $facts['cause'],
                'killer' => $facts['killer'],
                'weapon' => $facts['weapon'],
                'distance_meters' => $facts['distance_m'],
                // The ONLY age is playtime (life clock). Never reference wall-clock time.
                'age_playtime' => $facts['playtime_human'],
                'associates_left_behind' => $facts['associates'],
                'prior_life' => $facts['prior_death'],
            ],
            'raw_admin_log_excerpt' => $facts['raw_log'],
        ];

        $intro = "Write an OBITUARY for a survivor who just died, using how they died, how old they were (their age is 'age_playtime' — actual time played; never invent a different age), who killed them and with what, and any associates left behind.";

        return $intro."\n\nDETAILS (JSON):\n".$this->json($payload);
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array{headline:string,body:string} */
    private function split(string $raw): array
    {
        $raw = trim($raw);
        $parts = preg_split('/\r\n|\r|\n/', $raw, 2);
        $headline = trim($parts[0] ?? '');
        $body = trim($parts[1] ?? '');
        if ($body === '') {
            $body = $headline;
            $headline = '📰 THE ONE LIFE TRIBUNE';
        }

        return ['headline' => $headline, 'body' => $body];
    }

    /** @return array{headline:string,body:string} */
    private function fallback(string $kind, array $facts): array
    {
        $picker = $this->picker ?? new MessagePicker();

        if ($kind === 'birth') {
            $key = 'birth.fallback';
        } else {
            $cause = $facts['cause'];
            $bucket = match (true) {
                $cause === 'pvp' && $facts['killer'] => 'pvp',
                $cause === 'suicide' => 'suicide',
                in_array($cause, ['environment', 'bled_out', 'drowned'], true) => 'environment',
                default => 'misc',
            };
            $key = "eulogy.{$bucket}";
        }

        $pool = config("personality.{$key}", []);
        if (! is_array($pool) || $pool === []) {
            return ['headline' => '📰 THE ONE LIFE TRIBUNE', 'body' => 'Another chapter closes on the coast. {{PLAYER}}.'];
        }

        $pool = array_values($pool);
        // Reuse MessagePicker's anti-repeat chooser by indexing into the structured pool ourselves.
        $index = $picker->indexFor($key, count($pool));
        $entry = $pool[$index];

        return ['headline' => $entry['headline'], 'body' => $entry['body']];
    }
}
