<?php

namespace App\Services\Tokens;

use App\Models\Player;
use App\Services\State\BotState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RewardService
{
    public function __construct(private BotState $state) {}

    /**
     * Grant monthly tokens once per calendar month. Returns
     * ['granted' => int totalTokens, 'players' => [['discord_user_id','gamertag','amount'], ...]].
     */
    public function monthlyGrant(CarbonImmutable $now): array
    {
        $monthKey = $now->format('Y-m');
        if ($this->state->get('last_reward_month') === $monthKey) {
            return ['granted' => 0, 'players' => []];
        }

        // Compute AND apply inside one transaction so a player can't link/unlink in
        // the gap between the read and the increments (which would over/under-count).
        $result = DB::transaction(function () use ($now) {
            $computed = $this->computeGrant($now);
            foreach ($computed['players'] as $entry) {
                Player::where('discord_user_id', $entry['discord_user_id'])
                    ->increment('unban_tokens', $entry['amount']);
            }
            return $computed;
        });

        $this->state->set('last_reward_month', $monthKey);

        return $result;
    }

    /**
     * Compute what monthlyGrant would distribute, WITHOUT writing anything.
     *
     * @return array{granted:int, players:array<int,array{discord_user_id:?string,gamertag:string,amount:int}>}
     */
    public function previewGrant(CarbonImmutable $now): array
    {
        return $this->computeGrant($now);
    }

    /**
     * Shared computation: per linked player, amount = 1 + active referrals in the previous month. No writes.
     *
     * @return array{granted:int, players:array<int,array{discord_user_id:?string,gamertag:string,amount:int}>}
     */
    private function computeGrant(CarbonImmutable $now): array
    {
        $prevStart = $now->subMonthNoOverflow()->startOfMonth();
        $prevEnd = $now->startOfMonth();

        $breakdown = [];
        $total = 0;

        foreach (Player::whereNotNull('discord_user_id')->get() as $player) {
            $activeReferrals = Player::where('referrer_id', $player->id)
                ->whereHas('sessions', fn ($q) => $q->where('connected_at', '>=', $prevStart)->where('connected_at', '<', $prevEnd))
                ->count();
            $amount = 1 + $activeReferrals;
            $total += $amount;
            $breakdown[] = [
                'discord_user_id' => $player->discord_user_id,
                'gamertag' => $player->gamertag,
                'amount' => $amount,
            ];
        }

        return ['granted' => $total, 'players' => $breakdown];
    }
}
