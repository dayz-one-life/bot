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

        // "Previous calendar month" window [start, end).
        $prevStart = $now->subMonthNoOverflow()->startOfMonth();
        $prevEnd = $now->startOfMonth();

        $players = Player::whereNotNull('discord_user_id')->get();
        $breakdown = [];
        $total = 0;

        DB::transaction(function () use ($players, $prevStart, $prevEnd, &$breakdown, &$total) {
            foreach ($players as $player) {
                $activeReferrals = Player::where('referrer_id', $player->id)
                    ->whereHas('sessions', fn ($q) => $q->where('connected_at', '>=', $prevStart)->where('connected_at', '<', $prevEnd))
                    ->count();
                $amount = 1 + $activeReferrals;
                $player->increment('unban_tokens', $amount);
                $total += $amount;
                $breakdown[] = [
                    'discord_user_id' => $player->discord_user_id,
                    'gamertag' => $player->gamertag,
                    'amount' => $amount,
                ];
            }
        });

        $this->state->set('last_reward_month', $monthKey);

        return ['granted' => $total, 'players' => $breakdown];
    }
}
