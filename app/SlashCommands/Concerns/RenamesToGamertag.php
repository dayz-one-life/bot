<?php

namespace App\SlashCommands\Concerns;

trait RenamesToGamertag
{
    /** Discord nicknames are capped at 32 characters. */
    protected function nicknameForGamertag(string $gamertag): string
    {
        return mb_substr($gamertag, 0, 32);
    }

    /**
     * Best-effort: set a guild member's nickname to their gamertag. Never throws —
     * a missing permission, role-hierarchy block, or owner target silently no-ops.
     */
    protected function renameMemberToGamertag($member, string $gamertag): void
    {
        if (! $member || ! method_exists($member, 'setNickname')) {
            return;
        }
        try {
            $result = $member->setNickname($this->nicknameForGamertag($gamertag), 'Linked DayZ gamertag');
            if ($result instanceof \React\Promise\PromiseInterface) {
                $result->otherwise(fn () => null);
            }
        } catch (\Throwable) {
            // best-effort: never propagate to the link flow
        }
    }

    /**
     * Best-effort: resolve a guild member by user id (from the interaction's guild),
     * then rename. Handles the cached and the fetch-required cases. Never throws.
     */
    protected function renameUserIdToGamertag($interaction, string $userId, string $gamertag): void
    {
        try {
            $guild = $interaction->guild ?? null;
            if (! $guild) {
                return;
            }
            $member = $guild->members->get('id', $userId);
            if ($member) {
                $this->renameMemberToGamertag($member, $gamertag);
                return;
            }
            $guild->members->fetch($userId)
                ->then(fn ($m) => $m ? $this->renameMemberToGamertag($m, $gamertag) : null)
                ->otherwise(fn () => null);
        } catch (\Throwable) {
            // best-effort
        }
    }
}
