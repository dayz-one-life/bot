<?php

namespace App\Services;

use App\Services\State\BotState;
use App\Services\Tokens\RewardService;
use Carbon\CarbonImmutable;
use Laracord\Laracord;
use Laracord\Services\Service;

class MonthlyRewardService extends Service
{
    protected int $interval = 3600; // hourly check; the grant itself is once-per-month

    /**
     * Override parent constructor so the service can be instantiated without
     * a bot in unit tests (handle() needs bot).
     */
    public function __construct(?Laracord $bot = null)
    {
        if ($bot !== null) {
            parent::__construct($bot);
        }
    }

    public function handle(): void
    {
        try {
            $result = (new RewardService(new BotState()))->monthlyGrant(CarbonImmutable::now());
            if ($result['granted'] <= 0) {
                return;
            }

            $this->console()->info("[rewards] granted {$result['granted']} monthly token(s).");
            foreach ($result['players'] as $p) {
                if (! $p['discord_user_id'] || $p['amount'] <= 0) {
                    continue;
                }
                $this->dm($p['discord_user_id'], "🎁 Your monthly unban tokens have arrived: **+{$p['amount']}** (gamertag {$p['gamertag']}).");
            }
        } catch (\Throwable $e) {
            $this->console()->error('[rewards] monthly grant failed: '.$e->getMessage());
        }
    }

    private function dm(string $userId, string $content): void
    {
        try {
            $this->discord()->users->fetch($userId)
                ->then(fn ($user) => $user?->sendMessage($content))
                ->otherwise(fn () => null);
        } catch (\Throwable) {
            // best-effort
        }
    }
}
