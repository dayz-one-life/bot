<?php

namespace App\Services\Adm;

/**
 * PURE. Given a buffer of recent raw ADM lines (oldest-first), the victim's gamertag, and the
 * raw death line, returns a newline-joined excerpt of the lines that mention the victim plus the
 * death line appended last. This is the "death window" handed to the eulogy LLM for color.
 * Coordinate-/format-independent: matches on the quoted gamertag string.
 */
class DeathLogCapturer
{
    public function capture(array $buffer, string $victim, string $deathLine, int $maxLines = 40): string
    {
        $needle = '"'.$victim.'"';
        $matches = array_values(array_filter(
            $buffer,
            fn ($line) => is_string($line) && $line !== '' && str_contains($line, $needle)
        ));

        // Reserve one slot for the death line.
        $keep = max(0, $maxLines - 1);
        if (count($matches) > $keep) {
            $matches = array_slice($matches, -$keep);
        }

        $matches[] = $deathLine;

        return implode("\n", $matches);
    }
}
