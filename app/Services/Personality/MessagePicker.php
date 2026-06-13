<?php

namespace App\Services\Personality;

/**
 * Picks a random line from a personality pool (config/personality.php), avoiding the
 * immediately-previous line for that key, and interpolates :tokens. Best-effort: a missing
 * or empty pool returns the caller's plain fallback (or '' if none) so a message still sends.
 *
 * Randomness is injectable for deterministic tests: the chooser is
 * fn (array $pool, ?int $avoidIndex): int.
 */
class MessagePicker
{
    /**
     * Last-chosen index per pool key, shared process-wide across all instances.
     * Deliberate: the same line shouldn't repeat even when a notifier is constructed
     * fresh on each Discord event. Keyed by the config dot-key, so pools never collide.
     *
     * @var array<string,int>
     */
    private static array $last = [];

    private \Closure $chooser;

    public function __construct(?\Closure $chooser = null)
    {
        $this->chooser = $chooser ?? function (array $pool, ?int $avoid): int {
            if (count($pool) <= 1) {
                return 0;
            }
            do {
                $index = array_rand($pool);
            } while ($index === $avoid);

            return $index;
        };
    }

    /**
     * @param  array<string,mixed>  $tokens  e.g. [':target' => '<@123>', ':tokens' => 2]
     */
    public function pick(string $key, array $tokens = [], ?string $fallback = null): string
    {
        $pool = config("personality.{$key}");

        if (! is_array($pool) || $pool === []) {
            return $fallback === null ? '' : strtr($fallback, $this->stringTokens($tokens));
        }

        $pool = array_values($pool);
        $index = ($this->chooser)($pool, self::$last[$key] ?? null);
        self::$last[$key] = $index;

        return strtr($pool[$index], $this->stringTokens($tokens));
    }

    /**
     * Clear the process-wide anti-repeat state. Intended for tests.
     */
    public static function reset(): void
    {
        self::$last = [];
    }

    /**
     * @param  array<string,mixed>  $tokens
     * @return array<string,string>
     */
    private function stringTokens(array $tokens): array
    {
        return array_map(fn ($value) => (string) $value, $tokens);
    }
}
