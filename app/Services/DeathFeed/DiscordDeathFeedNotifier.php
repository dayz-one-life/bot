<?php

namespace App\Services\DeathFeed;

use App\Models\Ban;
use App\Models\Life;
use App\Services\Personality\MessagePicker;
use Carbon\CarbonImmutable;
use Discord\Discord;

/**
 * Posts the merged death-feed line (kill detail + ban return time) to the bans channel.
 * This OWNS the public death announcement (DiscordBanNotifier no longer channel-posts
 * ban.death). A public post, so DeathFeedComposer mentions linked players.
 *
 * Entirely best-effort: a null client, missing channel id, or send failure all silently
 * no-op so ingestion/ban reconciliation never breaks on a Discord hiccup.
 */
class DiscordDeathFeedNotifier implements DeathFeedNotifier
{
    private DeathFeedComposer $composer;

    public function __construct(private ?Discord $discord, private ?string $channelId, ?DeathFeedComposer $composer = null)
    {
        $this->composer = $composer ?? new DeathFeedComposer(new MessagePicker());
    }

    public function died(Life $life, Ban $ban): void
    {
        $expires = $ban->expires_at ?? CarbonImmutable::now();
        $this->toChannel($this->composer->compose($life, $expires));
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
