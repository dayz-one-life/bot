<?php

namespace App\Services\Lookup;

use App\Models\Ban;
use App\Models\Player;
use Carbon\CarbonImmutable;

/**
 * Data providers for slash-command gamertag autocomplete.
 *
 * Every method returns a plain array list of gamertag strings (<=25). This is
 * deliberate: Laracord's SlashCommand::handleAutocomplete() passes the result
 * into Arr::isList(), which is typed `array` and throws a TypeError on a
 * Collection — surfacing in Discord as "Loading options failed".
 */
class GamertagLookup
{
    /**
     * Recent players by last seen, optionally filtered by link status and search.
     *
     * @param  ?bool  $linked  null = all, true = linked only, false = unlinked only
     * @return list<string>
     */
    public function players(?string $search = null, ?bool $linked = null): array
    {
        return Player::query()
            ->when($linked === true, fn ($q) => $q->whereNotNull('discord_user_id'))
            ->when($linked === false, fn ($q) => $q->whereNull('discord_user_id'))
            ->when($search, fn ($q) => $q->where('gamertag', 'like', "%{$search}%"))
            ->orderByDesc('last_seen_at')
            ->limit(25)
            ->pluck('gamertag')
            ->all();
    }

    /**
     * Gamertags with an active ban, optionally restricted to currently temporary bans.
     *
     * @return list<string>
     */
    public function bannedGamertags(?string $search = null, bool $temporaryOnly = false): array
    {
        $now = CarbonImmutable::now();

        return Ban::query()
            ->where('expired', false)
            ->when($temporaryOnly, fn ($q) => $q->whereNotNull('expires_at')->where('expires_at', '>', $now))
            ->with('player')
            ->get()
            ->map(fn (Ban $b) => $b->player?->gamertag)
            ->filter()
            ->unique()
            ->when($search, fn ($c) => $c->filter(fn ($t) => str_contains(strtolower((string) $t), strtolower($search))))
            ->take(25)
            ->values()
            ->all();
    }
}
