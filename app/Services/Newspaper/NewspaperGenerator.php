<?php

namespace App\Services\Newspaper;

use App\Services\Llm\OpenRouterClient;
use App\Services\Personality\MessagePicker;

/**
 * Generates the three prose sections (editorial, recap, classifieds) of a Tribune issue in ONE
 * OpenRouter call, split on explicit "## EDITORIAL / ## RECAP / ## CLASSIFIEDS" delimiters. Any
 * failure — no key, timeout, non-2xx, empty, or a missing section — falls back per-section to the
 * canned `personality.newspaper.*` pools (interpolated with the week's counts). Same hard rules as
 * the eulogy generator PLUS the Tribune location policy.
 */
class NewspaperGenerator
{
    private const SECTIONS = ['editorial', 'recap', 'classifieds'];

    private const SYSTEM = <<<'TXT'
You are the entire editorial staff of "The One Life Tribune", a savage, witty post-apocalyptic
newspaper covering a hardcore DayZ "one life" server. Players get ONE life; when they die they are
banned for a while, so every death is a real funeral.

Write a full weekly issue with THREE sections. Be funny, a little roasty, creative. Use Discord
markdown (**bold**, *italics*, `> blockquotes`, fitting emojis).

Output EXACTLY these three delimited sections, in this order, with nothing before the first:
## EDITORIAL
<a 120-250 word op-ed themed on the week's biggest story or trend>
## RECAP
<a 120-250 word narrative of the week's real events>
## CLASSIFIEDS
<4-6 short, funny fake classified ads seeded from the real events>

HARD RULES:
- Use ONLY the facts you are given. NEVER invent names, weapons, distances, kills, or events.
- Refer to players by their exact gamertag from the data. Any witness/reaction quote MUST be
  attributed to one of the gamertags in 'witnesses' (never an invented anonymous bystander). If
  'witnesses' is empty, omit quotes.
- LOCATION POLICY (critical): You MAY mention a town/region name ONLY when it appears in the
  'location_trends' data, and ONLY as an aggregate trend ("infected attacks around Cherno are up").
  NEVER state or imply WHERE a specific named player was, died, fought, or lives. NEVER output
  coordinates or grid references. NEVER mention player bases or build events. When in doubt, omit
  the location.
- If a section has little data (a quiet week), say so wittily rather than inventing detail.
TXT;

    public function __construct(
        private OpenRouterClient $client,
        private ?MessagePicker $picker = null,
    ) {}

    /**
     * @param array<string,mixed> $facts
     * @return array{editorial:string,recap:string,classifieds:string}
     */
    public function generate(array $facts): array
    {
        try {
            $raw = $this->client->complete(self::SYSTEM, $this->userPrompt($facts));
            $parsed = $this->split($raw);
        } catch (\Throwable) {
            $parsed = ['editorial' => '', 'recap' => '', 'classifieds' => ''];
        }

        foreach (self::SECTIONS as $section) {
            if (($parsed[$section] ?? '') === '') {
                $parsed[$section] = $this->fallback($section, $facts);
            }
        }

        return $parsed;
    }

    private function userPrompt(array $facts): string
    {
        return "Write this week's issue from these facts (JSON):\n".
            json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array{editorial:string,recap:string,classifieds:string} */
    private function split(string $raw): array
    {
        $out = ['editorial' => '', 'recap' => '', 'classifieds' => ''];
        $parts = preg_split('/^##\s*(EDITORIAL|RECAP|CLASSIFIEDS)\s*$/mi', trim($raw), -1, PREG_SPLIT_DELIM_CAPTURE);
        for ($i = 1; $i < count($parts); $i += 2) {
            $key = strtolower(trim($parts[$i]));
            $body = trim($parts[$i + 1] ?? '');
            if (array_key_exists($key, $out)) {
                $out[$key] = $body;
            }
        }

        return $out;
    }

    private function fallback(string $section, array $facts): string
    {
        $picker = $this->picker ?? new MessagePicker();
        $pool = config("personality.newspaper.{$section}", []);
        if (! is_array($pool) || $pool === []) {
            return 'Slow news week on the coast.';
        }
        $pool = array_values($pool);
        $entry = $pool[$picker->indexFor("newspaper.{$section}", count($pool))];

        return strtr($entry, [
            ':lives_lost' => (string) ($facts['counts']['lives_lost'] ?? 0),
            ':playtime' => (string) ($facts['counts']['playtime_human'] ?? '0m'),
        ]);
    }
}
