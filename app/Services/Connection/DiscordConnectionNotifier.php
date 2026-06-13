<?php

namespace App\Services\Connection;

use Discord\Discord;

/**
 * Posts connect/disconnect lines to the configured connections channel.
 *
 * Deliberately does NOT use PlayerMention: this is a high-volume channel, so we
 * never @-mention linked Discord users (an intentional exception to the repo's
 * "public channel posts mention" rule). Plain backticked gamertag only.
 *
 * Entirely best-effort: a null client, missing channel id, or send failure all
 * silently no-op so ingestion never breaks on a Discord hiccup.
 */
class DiscordConnectionNotifier implements ConnectionNotifier
{
    public function __construct(private ?Discord $discord, private ?string $channelId) {}

    public function connected(string $gamertag, \DateTimeImmutable $ts): void
    {
        $this->toChannel("🟢 `{$gamertag}` connected");
    }

    public function disconnected(string $gamertag, \DateTimeImmutable $ts, ?int $sessionSeconds): void
    {
        $tail = $sessionSeconds === null ? '' : ' · on for '.SessionDuration::human($sessionSeconds);
        $this->toChannel("🔴 `{$gamertag}` disconnected{$tail}");
    }

    private function toChannel(string $content): void
    {
        if (! $this->discord || ! $this->channelId) {
            return;
        }

        try {
            $channel = $this->discord->getChannel($this->channelId);

            if (! $channel) {
                return;
            }

            $channel->sendMessage($content)->otherwise(fn () => null);
        } catch (\Throwable) {
            // best-effort: never propagate to caller
        }
    }
}
