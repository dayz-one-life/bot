<?php

namespace App\Services\Ban;

use App\Models\Ban;
use App\Models\Player;
use App\Services\Lookup\PlayerMention;
use App\Services\Personality\MessagePicker;
use Discord\Discord;

class DiscordBanNotifier implements BanNotifier
{
    private MessagePicker $picker;

    public function __construct(private ?Discord $discord, private ?string $bansChannelId, ?MessagePicker $picker = null)
    {
        $this->picker = $picker ?? new MessagePicker();
    }

    public function banned(Ban $ban, Player $player, bool $isExtension): void
    {
        $who = (new PlayerMention())->forPlayer($player);
        $expires = $ban->expires_at ? "<t:{$ban->expires_at->timestamp}:f>" : 'never (permanent)';
        $key = self::bannedKey($ban, $isExtension);

        $fallbackAction = $isExtension ? 'Ban updated' : 'Player banned';
        $this->toChannel($this->picker->pick(
            $key,
            [':who' => $who, ':reason' => $ban->reason, ':expires' => $expires],
            "🔨 **{$fallbackAction}** — {$who} · {$ban->reason} · expires {$expires}"
        ));

        if ($player->discord_user_id) {
            $dmFallback = "🔨 You have been **banned** from the server.\n• Reason: {$ban->reason}\n• Expires: {$expires}";
            $dm = $key === 'ban.death'
                ? $this->picker->pick('ban.dm.death', [':expires' => $expires], $dmFallback)
                : $this->picker->pick('ban.dm.manual', [':reason' => $ban->reason, ':expires' => $expires], $dmFallback);
            $this->toUser($player->discord_user_id, $dm);
        }
    }

    public function unbanned(Player $player, string $reason, ?string $originalReason): void
    {
        $who = (new PlayerMention())->forPlayer($player);
        $this->toChannel($this->picker->pick(
            'ban.unbanned',
            [':who' => $who, ':reason' => $reason],
            "✅ **Player unbanned** — {$who} · {$reason}"
        ));

        if ($player->discord_user_id) {
            $this->toUser($player->discord_user_id, $this->picker->pick(
                'ban.dm.unbanned',
                [':reason' => $reason],
                "🕊️ Your ban has been removed.\n• Reason: {$reason}"
            ));
        }
    }

    /**
     * Map a ban to its personality pool key.
     * Public + static so it is unit-testable without a Discord gateway.
     */
    public static function bannedKey(Ban $ban, bool $isExtension): string
    {
        if ($isExtension) {
            return 'ban.extended';
        }

        return $ban->source === 'auto_death' ? 'ban.death' : 'ban.manual';
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
