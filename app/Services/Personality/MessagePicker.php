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
    /** @var array<string,int> last-chosen index per key, shared across instances (long-running bot) */
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
     * @param  array<string,mixed>  $tokens
     * @return array<string,string>
     */
    private function stringTokens(array $tokens): array
    {
        return array_map(fn ($value) => (string) $value, $tokens);
    }
}
