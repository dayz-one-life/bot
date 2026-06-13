<?php

namespace App\Services\Connection;

use App\Services\Personality\MessagePicker;
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
    private MessagePicker $picker;

    public function __construct(private ?Discord $discord, private ?string $channelId, ?MessagePicker $picker = null)
    {
        $this->picker = $picker ?? new MessagePicker();
    }

    public function connected(string $gamertag, \DateTimeImmutable $ts): void
    {
        $tag = "`{$gamertag}`";
        $this->toChannel($this->picker->pick('connection.connected', [':tag' => $tag], "🟢 {$tag} connected"));
    }

    public function disconnected(string $gamertag, \DateTimeImmutable $ts, ?int $sessionSeconds): void
    {
        $tag = "`{$gamertag}`";

        if ($sessionSeconds === null) {
            $this->toChannel($this->picker->pick('connection.disconnected_nodur', [':tag' => $tag], "🔴 {$tag} disconnected"));

            return;
        }

        $duration = SessionDuration::human($sessionSeconds);
        $this->toChannel($this->picker->pick(
            'connection.disconnected',
            [':tag' => $tag, ':duration' => $duration],
            "🔴 {$tag} disconnected · on for {$duration}"
        ));
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
