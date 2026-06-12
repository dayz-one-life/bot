<?php

namespace App\Services\Ban;

use App\Models\Ban;
use App\Models\Player;
use App\Services\Nitrado\NitradoClient;
use Carbon\CarbonImmutable;

class BanService
{
    public function __construct(
        private NitradoClient $nitrado,
        private BanNotifier $notifier,
        private bool $dryRun = false,
    ) {}

    public function ban(string $gamertag, int $hours, string $reason, string $source): Ban
    {
        $now = CarbonImmutable::now();
        $player = Player::firstOrCreate(
            ['gamertag' => $gamertag],
            ['first_seen_at' => $now, 'last_seen_at' => $now]
        );
        $expiresAt = $hours > 0 ? $now->addHours($hours) : null;

        $existing = Ban::where('player_id', $player->id)
            ->where('expired', false)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->latest('banned_at')->first();

        $isExtension = (bool) $existing;
        if ($existing) {
            $existing->update(['banned_at' => $now, 'expires_at' => $expiresAt, 'reason' => $reason, 'source' => $source]);
            $ban = $existing;
        } else {
            $ban = Ban::create([
                'player_id' => $player->id,
                'banned_at' => $now,
                'expires_at' => $expiresAt,
                'expired' => false,
                'reason' => $reason,
                'source' => $source,
            ]);
        }

        if (! $this->dryRun) {
            $this->nitrado->addBan($gamertag);
        }
        $this->notifier->banned($ban, $player, $isExtension);

        return $ban;
    }

    public function unban(string $gamertag, string $reason): void
    {
        if (! $this->dryRun) {
            $this->nitrado->removeBan($gamertag);
        }

        $player = Player::where('gamertag', $gamertag)->first();
        if (! $player) return;

        $active = Ban::where('player_id', $player->id)->where('expired', false)->get();
        if ($active->isEmpty()) return; // nothing was actually lifted — don't notify

        Ban::whereIn('id', $active->pluck('id'))->update(['expired' => true, 'expires_at' => CarbonImmutable::now()]);
        $this->notifier->unbanned($player, $reason, $active->first()->reason);
    }
}
