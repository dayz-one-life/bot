<?php

namespace App\Services\Ban;

use App\Models\Ban;
use App\Models\Player;
use App\Services\Lookup\PlayerMention;
use Discord\Discord;

class DiscordBanNotifier implements BanNotifier
{
    public function __construct(private ?Discord $discord, private ?string $bansChannelId) {}

    public function banned(Ban $ban, Player $player, bool $isExtension): void
    {
        $who = (new PlayerMention())->forPlayer($player);
        $expires = $ban->expires_at ? "<t:{$ban->expires_at->timestamp}:f>" : 'never (permanent)';
        $action = $isExtension ? 'Ban updated' : 'Player banned';
        $this->toChannel("🔨 **{$action}** — {$who} · {$ban->reason} · expires {$expires}");

        if ($player->discord_user_id) {
            $this->toUser($player->discord_user_id,
                "🔨 You have been **banned** from the server.\n• Reason: {$ban->reason}\n• Expires: {$expires}");
        }
    }

    public function unbanned(Player $player, string $reason, ?string $originalReason): void
    {
        $who = (new PlayerMention())->forPlayer($player);
        $this->toChannel("✅ **Player unbanned** — {$who} · {$reason}");

        if ($player->discord_user_id) {
            $this->toUser($player->discord_user_id, "🕊️ Your ban has been removed.\n• Reason: {$reason}");
        }
    }

    /**
     * Post a plain-text message to the configured bans channel.
     * Entirely best-effort: null discord client, missing channel, or send failure all silently no-op.
     */
    private function toChannel(string $content): void
    {
        if (! $this->discord || ! $this->bansChannelId) {
            return;
        }

        try {
            $channel = $this->discord->getChannel($this->bansChannelId);

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
