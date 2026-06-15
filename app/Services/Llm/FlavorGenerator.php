<?php

namespace App\Services\Llm;

use App\Services\Personality\MessagePicker;

/**
 * Generates a single cheeky one-line PUBLIC channel announcement (bounty + ban events) via
 * OpenRouter, substituting {{PLACEHOLDER}} tokens with the caller's channel values (mentions,
 * counts, timestamps). Any failure — no key, timeout, non-2xx, empty, or an un-substituted
 * placeholder left in the output — falls back to the canned personality pool for the same key,
 * byte-for-byte the pre-LLM behavior. DMs are NOT handled here (they stay canned).
 */
class FlavorGenerator
{
    private const SYSTEM = <<<'TXT'
You are the cheeky announcer for a hardcore DayZ "one life" Discord server, where players get ONE
life and a death means a ban. Write ONE short, punchy, funny line (a single sentence, ~10-25 words)
for the public feed. Light Discord markdown (**bold**) and a fitting emoji or two are good.

Rules:
- Include EVERY placeholder token you are told to use, written EXACTLY as given (e.g. {{TARGET}}),
  and invent no others. NEVER write a real name, number, or date in a placeholder's place.
- Use ONLY the information given. Never fabricate weapons, distances, reasons, kill counts, or times.
- NEVER reveal map locations: no coordinates, grid references, or in-world place names.
- Output the single line ONLY — no surrounding quotes, no headline, no preamble, no extra lines.
TXT;

    public function __construct(
        private OpenRouterClient $client,
        private ?MessagePicker $picker = null,
    ) {}

    public static function fromConfig(): self
    {
        return new self(OpenRouterClient::fromConfig(), new MessagePicker());
    }

    /**
     * @param  string  $key      personality dot-key, e.g. 'bounty.placed' / 'ban.death'
     * @param  array<string,mixed>  $tokens  placeholder name => channel value, e.g.
     *         ['target' => '<@123>', 'tokens' => 2]; mapped to {{TARGET}} for the LLM and
     *         :target for the canned fallback.
     * @param  string  $fallback  the plain literal already passed to the pool today
     */
    public function line(string $key, array $tokens, string $fallback): string
    {
        try {
            $raw = $this->client->complete(self::SYSTEM, $this->userPrompt($key));
            $line = $this->substitute(trim($raw), $tokens);

            // A residual {{TOKEN}} means the model invented/kept a token we have no value for:
            // treat it as a failure so we never ship a raw placeholder to the channel.
            if ($line === '' || preg_match('/\{\{[A-Z_]+\}\}/', $line) === 1) {
                throw new \RuntimeException('empty or unsubstituted placeholder');
            }

            return $line;
        } catch (\Throwable) {
            $picker = $this->picker ?? new MessagePicker();

            return $picker->pick($key, $this->colonTokens($tokens), $fallback);
        }
    }

    private function userPrompt(string $key): string
    {
        return match ($key) {
            'bounty.placed' => 'Announce that a NEW bounty is now on {{TARGET}} — kill them to earn an unban token. Build the tension. Include: {{TARGET}}.',
            'bounty.moved' => 'Announce that the bounty has MOVED: {{TARGET}} is now the longest-surviving target, the one everyone should hunt. Include: {{TARGET}}.',
            'bounty.claimed' => 'Celebrate that {{KILLER}} hunted down bounty target {{TARGET}} and collected {{TOKENS}} unban token(s). Include: {{KILLER}}, {{TARGET}}, {{TOKENS}}.',
            'bounty.ended' => 'Announce ONLY that the bounty on {{TARGET}} is no longer active. CRITICAL: stay strictly neutral — do NOT say or imply whether any reward, token, payout, or claim happened, and never use the words "token", "reward", "paid", or "claim". Include: {{TARGET}}.',
            'ban.death' => 'A survivor ran out of their ONE life and is benched. Announce that {{WHO}} is banned until {{EXPIRES}} ({{REASON}}). Frame it as the CONSEQUENCE — do NOT retell how they died. Include: {{WHO}}, {{REASON}}, {{EXPIRES}}.',
            'ban.manual' => 'Announce that {{WHO}} has caught a ban ({{REASON}}) and is out until {{EXPIRES}}. Include: {{WHO}}, {{REASON}}, {{EXPIRES}}.',
            'ban.extended' => "Announce that {{WHO}}'s ban was extended/remixed ({{REASON}}); it now expires {{EXPIRES}}. Include: {{WHO}}, {{REASON}}, {{EXPIRES}}.",
            'ban.unbanned' => 'Announce that {{WHO}} is free again — their ban was lifted ({{REASON}}). Tell them to try to keep this life alive. Include: {{WHO}}, {{REASON}}.',
            default => 'Write a short announcement and include every {{PLACEHOLDER}} you were given.',
        };
    }

    /** @param array<string,mixed> $tokens */
    private function substitute(string $line, array $tokens): string
    {
        $map = [];
        foreach ($tokens as $name => $value) {
            $map['{{'.strtoupper($name).'}}'] = (string) $value;
        }

        return strtr($line, $map);
    }

    /**
     * @param  array<string,mixed>  $tokens
     * @return array<string,mixed>  ':name' => value, for the MessagePicker::pick fallback
     */
    private function colonTokens(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $name => $value) {
            $out[':'.$name] = $value;
        }

        return $out;
    }
}
