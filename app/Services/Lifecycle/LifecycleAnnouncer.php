<?php

namespace App\Services\Lifecycle;

use App\Models\Life;
use App\Services\Life\LivePlaytime;
use App\Services\State\BotState;
use Carbon\CarbonImmutable;

/**
 * Scans for due births and eulogies and posts them, idempotently. A life "counts" (birth + eulogy)
 * once its playtime >= grace; both are additionally gated by go_live_at and a freshness window
 * (mirrors the death feed's anti-backlog behavior). Births fire ~grace after a sticky spawn;
 * eulogies fire when a counted life ends in death.
 */
class LifecycleAnnouncer
{
    private const BIRTH_COLOR = 0x57F287;  // green
    private const EULOGY_COLOR = 0x2B2D31; // near-black

    public function __construct(
        private AnnouncementGenerator $generator,
        private LifecycleNotifier $notifier,
        private BotState $state,
        private int $graceSeconds = 300,
        private int $maxAgeMinutes = 30,
        private ?LifeFactsBuilder $facts = null,
        private ?MentionSubstitutor $substitutor = null,
    ) {}

    public function run(): void
    {
        $goLive = $this->state->get('go_live_at');
        if (! $goLive) return; // not live — never retro-announce backfill

        $cutoff = CarbonImmutable::parse($goLive);
        $fresh = CarbonImmutable::now()->subMinutes($this->maxAgeMinutes);

        $this->announceBirths($cutoff, $fresh);
        $this->announceEulogies($cutoff, $fresh);
    }

    private function announceBirths(CarbonImmutable $goLive, CarbonImmutable $fresh): void
    {
        // Candidates: not yet announced, started after go_live, recent.
        $candidates = Life::query()
            ->whereNull('birth_announced_at')
            ->where('started_at', '>', $goLive)
            ->where('started_at', '>=', $fresh)
            ->with('player')
            ->orderBy('started_at')
            ->get();

        foreach ($candidates as $life) {
            $playtime = $life->ended_at ? (int) $life->playtime_seconds : LivePlaytime::forLife($life);
            if ($playtime < $this->graceSeconds) continue;

            $facts = $this->factsBuilder()->build($life);
            $copy = $this->generator->generate('birth', $facts);
            $this->notifier->publishBirth($this->payload($copy, $facts, self::BIRTH_COLOR, $life, 'born'));
            $life->update(['birth_announced_at' => CarbonImmutable::now()]);
        }
    }

    private function announceEulogies(CarbonImmutable $goLive, CarbonImmutable $fresh): void
    {
        $candidates = Life::query()
            ->whereNotNull('ended_at')
            ->where('ended_at', '>', $goLive)
            ->where('ended_at', '>=', $fresh)
            ->where('playtime_seconds', '>=', $this->graceSeconds)
            ->where('eulogy_posted', false)
            ->with('player')
            ->orderBy('ended_at')
            ->get();

        foreach ($candidates as $life) {
            $facts = $this->factsBuilder()->build($life);
            $copy = $this->generator->generate('eulogy', $facts);
            $this->notifier->publishEulogy($this->payload($copy, $facts, self::EULOGY_COLOR, $life, 'died'));
            $life->update(['eulogy_posted' => true]);
        }
    }

    /**
     * @param array{headline:string,body:string} $copy
     * @param array<string,mixed> $facts
     * @return array<string,mixed>
     */
    private function payload(array $copy, array $facts, int $color, Life $life, string $verb): array
    {
        // PLAIN gamertags inside the embed — NOT <@id> mentions. Discord renders a mention in an
        // embed *description* but NOT in the *title* (it shows the raw "<@123>"), and a newspaper
        // article reads better with the bare name anyway. The real, notifying mention rides the
        // content `ping` line instead (see ping()).
        $names = $this->nameMap($facts);

        return [
            'ping' => $this->ping($facts, $verb),
            'title' => strtr($copy['headline'], $names),
            'description' => strtr($copy['body'], $names),
            'fields' => $this->fields($facts, $verb),
            'color' => $color,
            'footer' => $this->footer($life, $verb),
        ];
    }

    /**
     * Placeholder -> plain gamertag (nulls skipped so an absent killer leaves no stray token value).
     *
     * @param array<string,mixed> $facts
     * @return array<string,string>
     */
    private function nameMap(array $facts): array
    {
        $map = [];
        foreach (['{{PLAYER}}' => $facts['gamertag'], '{{KILLER}}' => $facts['killer']] as $token => $gamertag) {
            if ($gamertag !== null && $gamertag !== '') {
                $map[$token] = $gamertag;
            }
        }

        return $map;
    }

    /**
     * The plain content line carrying real <@id> notifications (Discord doesn't notify on mentions
     * inside an embed). Pings the subject if linked, and — for a eulogy — the killer if linked too.
     * Only real mentions (resolved to <@id>) are included; an unlinked name would add noise without
     * a ping, so it is omitted. Returns null when there is nobody to notify.
     */
    private function ping(array $facts, string $verb): ?string
    {
        $sub = $this->substitutor ?? new MentionSubstitutor();

        $subject = $facts['linked']
            ? $sub->apply('{{PLAYER}}', ['{{PLAYER}}' => $facts['gamertag']])
            : null;

        if ($verb === 'born') {
            return $subject ? "🎉 {$subject} enters the world." : null;
        }

        $line = $subject ? "🕯️ {$subject} has fallen." : null;

        // Also ping the killer, but only when they're a linked player (a real <@id> mention).
        $killer = $facts['killer'] ?? null;
        if ($killer !== null) {
            $killerMention = $sub->apply('{{KILLER}}', ['{{KILLER}}' => $killer]);
            if (str_starts_with($killerMention, '<@')) {
                $line = $line === null
                    ? "🎯 {$killerMention} claimed a life."
                    : $line." 🎯 {$killerMention} gets the credit.";
            }
        }

        return $line;
    }

    /** @return array<int,array{name:string,value:string}> */
    private function fields(array $facts, string $verb): array
    {
        // A newborn life has no meaningful stats yet — its "age" is always just the grace window,
        // and "associates left behind" only makes sense for a death. So births carry no fields;
        // the footer already says "born N ago".
        if ($verb !== 'died') {
            return [];
        }

        $fields = [
            ['name' => '🎂 Age', 'value' => "{$facts['playtime_human']} played"],
            ['name' => '☠️ Cause', 'value' => ucfirst((string) ($facts['cause'] ?? 'unknown'))],
        ];

        if ($facts['killer']) {
            $weapon = $facts['weapon'] ? " with {$facts['weapon']}" : '';
            $dist = $facts['distance_m'] !== null ? ' @ '.round((float) $facts['distance_m']).'m' : '';
            $fields[] = ['name' => '🔫 Killer', 'value' => "`{$facts['killer']}`{$weapon}{$dist}"];
        }
        if (! empty($facts['associates'])) {
            $fields[] = ['name' => '🤝 Known associates', 'value' => '`'.implode('`, `', $facts['associates']).'`'];
        }

        return $fields;
    }

    private function footer(Life $life, string $verb): string
    {
        $when = $verb === 'born' ? $life->started_at : ($life->ended_at ?? CarbonImmutable::now());
        return 'The One Life Tribune · '.$verb.' '.CarbonImmutable::parse($when)->diffForHumans();
    }

    private function factsBuilder(): LifeFactsBuilder
    {
        return $this->facts ?? new LifeFactsBuilder();
    }
}
