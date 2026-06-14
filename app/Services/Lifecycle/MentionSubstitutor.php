<?php

namespace App\Services\Lifecycle;

use App\Services\Lookup\PlayerMention;

/**
 * PURE-ish: replaces {{PLAYER}} / {{KILLER}} placeholders in generated copy with a rendered
 * gamertag — a real <@id> mention for linked players, a backticked tag otherwise — via the
 * shared PlayerMention rule. Each map value is a gamertag (or null).
 */
class MentionSubstitutor
{
    public function __construct(private ?PlayerMention $mention = null) {}

    /**
     * @param array<string,?string> $map placeholder => gamertag
     */
    public function apply(string $text, array $map): string
    {
        $mention = $this->mention ?? new PlayerMention();
        $replacements = [];
        foreach ($map as $placeholder => $gamertag) {
            if ($gamertag === null || $gamertag === '') continue;
            $replacements[$placeholder] = $mention->for($gamertag);
        }

        return strtr($text, $replacements);
    }
}
