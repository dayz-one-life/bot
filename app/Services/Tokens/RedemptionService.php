<?php

namespace App\Services\Tokens;

use App\Models\Ban;
use App\Models\Player;
use App\Services\Ban\BanService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RedemptionService
{
    public function __construct(private BanService $bans) {}

    /**
     * @return array{status:string, target?:string, remaining?:int}
     * status ∈ unbanned | not_linked | no_tokens | target_not_found | no_active_ban | permanent_ban
     */
    public function redeem(string $spenderDiscordId, ?string $targetGamertag): array
    {
        $spender = Player::where('discord_user_id', $spenderDiscordId)->first();
        if (! $spender) return ['status' => 'not_linked'];
        if ($spender->unban_tokens < 1) return ['status' => 'no_tokens'];

        $target = $targetGamertag
            ? Player::where('gamertag', $targetGamertag)->first()
            : $spender;
        if (! $target) return ['status' => 'target_not_found'];

        $now = CarbonImmutable::now();

        $permanent = Ban::where('player_id', $target->id)->where('expired', false)->whereNull('expires_at')->exists();
        if ($permanent) return ['status' => 'permanent_ban'];

        $activeTemp = Ban::where('player_id', $target->id)->where('expired', false)
            ->whereNotNull('expires_at')->where('expires_at', '>', $now)->exists();
        if (! $activeTemp) return ['status' => 'no_active_ban'];

        // Lift first; deduct only after a successful unban.
        $this->bans->unban($target->gamertag, "Unban token spent by {$spender->gamertag}");

        $remaining = DB::transaction(function () use ($spender, $target) {
            $spender->decrement('unban_tokens');
            $target->increment('used_tokens');
            return $spender->fresh()->unban_tokens;
        });

        return ['status' => 'unbanned', 'target' => $target->gamertag, 'remaining' => $remaining];
    }
}
