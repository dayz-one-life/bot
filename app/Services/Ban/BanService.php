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

        // Dry run is silent: no Nitrado write AND no Discord notification — only the
        // intended ban is recorded in the DB (verify there before the cutover).
        if (! $this->dryRun) {
            $this->nitrado->addBan($gamertag);
            $this->notifier->banned($ban, $player, $isExtension);
        }

        return $ban;
    }

    /**
     * Lift a ban. The DB is always updated (bookkeeping); the Nitrado removal and the
     * Discord notification fire only when we actually touch the live server.
     *
     * In dry-run mode the automated callers (expiry sweep) must NOT write to Nitrado.
     * A manual admin unban / token redemption passes $force = true: lifting a ban is a
     * deliberate corrective action that must reach the real server even during dry run,
     * so admins can clear erroneous live bans regardless of the dry-run lever.
     */
    public function unban(string $gamertag, string $reason, bool $force = false): void
    {
        $hitNitrado = $force || ! $this->dryRun;

        if ($hitNitrado) {
            $this->nitrado->removeBan($gamertag);
        }

        $player = Player::where('gamertag', $gamertag)->first();
        if (! $player) return;

        $active = Ban::where('player_id', $player->id)->where('expired', false)->get();
        if ($active->isEmpty()) return; // nothing was actually lifted — don't notify

        Ban::whereIn('id', $active->pluck('id'))->update(['expired' => true, 'expires_at' => CarbonImmutable::now()]);

        if ($hitNitrado) {
            $this->notifier->unbanned($player, $reason, $active->first()->reason);
        }
    }

    /** Whether this service is in dry-run mode (suppresses live Nitrado/Discord writes). */
    public function isDryRun(): bool
    {
        return $this->dryRun;
    }
}
