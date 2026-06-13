<?php

namespace App\Services\Bounty;

use App\Models\Bounty;
use App\Models\Player;
use App\Services\Lookup\PlayerMention;
use Discord\Discord;

class DiscordBountyNotifier implements BountyNotifier
{
    public function __construct(private ?Discord $discord, private ?string $channelId) {}

    public function placed(Bounty $bounty, Player $target): void
    {
        $this->toChannel("🎯 **Bounty placed** on ".(new PlayerMention())->forPlayer($target)." — kill them for an unban token!");
        if ($target->discord_user_id) {
            $this->toUser($target->discord_user_id, '🎯 A bounty has been placed on you. Watch your back.');
        }
    }

    public function moved(Bounty $bounty, Player $target): void
    {
        $this->toChannel("🎯 **Bounty moved** — ".(new PlayerMention())->forPlayer($target)." is now the longest-surviving target.");
        if ($target->discord_user_id) {
            $this->toUser($target->discord_user_id, '🎯 The bounty is now on you. Watch your back.');
        }
    }

    public function claimed(Bounty $bounty, Player $target, Player $killer, int $tokens): void
    {
        $mention = new PlayerMention();
        $who = $mention->forPlayer($killer);
        $targetDisplay = $mention->forPlayer($target);
        $this->toChannel("💀 **Bounty claimed!** {$who} killed {$targetDisplay} and earned {$tokens} unban token(s).");
        if ($killer->discord_user_id) {
            // The DM is private (not "everyone can see"), so use the plain gamertag, not a mention.
            $this->toUser($killer->discord_user_id, "💰 You claimed the bounty on `{$target->gamertag}` and earned {$tokens} unban token(s)!");
        }
    }

    public function ended(Bounty $bounty, Player $target, string $reason): void
    {
        // Neutral wording — never reveals whether a reward was paid, so an associate
        // pair cannot confirm a farm worked.
        $this->toChannel("🏳️ **Bounty ended** — the bounty on ".(new PlayerMention())->forPlayer($target)." is no longer active.");
    }

    /**
     * Post a plain-text message to the configured channel.
     * Entirely best-effort: null discord client, missing channel, or send failure all silently no-op.
     */
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

    /**
     * Send a DM to a Discord user by their snowflake ID.
     * Fetches via the users repository (may hit REST), then sends.
     * Entirely best-effort: missing user, closed DMs, or any error are all silently swallowed.
     */
    private function toUser(string $userId, string $content): void
    {
        if (! $this->discord) {
            return;
        }

        try {
            $this->discord->users
                ->fetch($userId)
                ->then(fn ($user) => $user?->sendMessage($content))
                ->otherwise(fn () => null);
        } catch (\Throwable) {
            // best-effort: never propagate to caller
        }
    }
}
