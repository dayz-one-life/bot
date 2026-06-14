<?php

namespace App\Services\Leaderboard;

use App\Services\State\BotState;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Embed\Embed;
use function React\Promise\all;

/**
 * Posts the 7 leaderboard board embeds once (one message each) and edits them in
 * place thereafter. The ordered list of message ids is persisted as JSON in
 * bot_state ('leaderboard_message_ids') alongside 'leaderboard_channel_id'.
 *
 * If the channel changed, the id count no longer matches, or ANY stored message
 * can't be fetched, the notifier reflushes: it deletes the stored messages (plus
 * the legacy single 'leaderboard_message_id' from the old single-embed layout) and
 * reposts all 7 sequentially so Discord display order matches the board order.
 *
 * Entirely best-effort: null client, missing channel, or any failure no-ops.
 */
class DiscordLeaderboardNotifier implements LeaderboardNotifier
{
    private BotState $state;

    public function __construct(private ?Discord $discord, private ?string $channelId, ?BotState $state = null)
    {
        $this->state = $state ?? new BotState();
    }

    public function publish(array $payloads): void
    {
        if (! $this->discord || ! $this->channelId) {
            return;
        }

        try {
            $channel = $this->discord->getChannel($this->channelId);
            if (! $channel) {
                return;
            }

            $ids = $this->storedIds();
            $storedChannel = $this->state->get('leaderboard_channel_id');

            if (count($ids) !== count($payloads) || $storedChannel !== $this->channelId) {
                $this->reflush($channel, $payloads);

                return;
            }

            // Edit in place only if EVERY message still exists; otherwise reflush
            // so the 7 messages stay in their canonical order.
            $fetches = array_map(fn ($id) => $channel->messages->fetch($id), $ids);

            all($fetches)->then(
                function ($messages) use ($payloads) {
                    foreach (array_values($messages) as $i => $message) {
                        $message->edit(MessageBuilder::new()->addEmbed($this->buildEmbed($payloads[$i])));
                    }
                },
                fn () => $this->reflush($channel, $payloads)
            );
        } catch (\Throwable) {
            // best-effort: never propagate to caller
        }
    }

    /** @return array<int, string> */
    private function storedIds(): array
    {
        $raw = $this->state->get('leaderboard_message_ids');
        if (! $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /**
     * Delete the legacy single message + the stored 7 (best-effort), then repost
     * all boards sequentially so display order matches board order.
     *
     * @param  array<int, array{key:string, title:string, description:string}>  $payloads
     */
    private function reflush($channel, array $payloads): void
    {
        // One-time migration away from the old single-embed layout.
        $legacy = $this->state->get('leaderboard_message_id');
        if ($legacy) {
            $channel->messages->fetch($legacy)
                ->then(fn ($m) => $m->delete())
                ->otherwise(fn () => null);
            $this->state->delete('leaderboard_message_id');
        }

        foreach ($this->storedIds() as $id) {
            $channel->messages->fetch($id)
                ->then(fn ($m) => $m->delete())
                ->otherwise(fn () => null);
        }

        $this->postSequential($channel, $payloads, 0, []);
    }

    /**
     * Post boards one after another (chained promises) so they land in order,
     * accumulating ids, then persist the id list + channel.
     *
     * Trade-off: if a send fails mid-sequence the chain stops and the id list is
     * never persisted, so the next tick reflushes (self-healing) — but any boards
     * already posted in the failed run have no stored id and won't be cleaned up,
     * leaving rare orphan embeds until manual deletion. Acceptable for a low-stakes,
     * 15-minute best-effort cosmetic feature.
     *
     * @param  array<int, array{key:string, title:string, description:string}>  $payloads
     * @param  array<int, string>  $ids
     */
    private function postSequential($channel, array $payloads, int $index, array $ids): void
    {
        if ($index >= count($payloads)) {
            $this->state->set('leaderboard_message_ids', json_encode($ids));
            $this->state->set('leaderboard_channel_id', (string) $this->channelId);

            return;
        }

        $channel->sendMessage(MessageBuilder::new()->addEmbed($this->buildEmbed($payloads[$index])))
            ->then(function ($message) use ($channel, $payloads, $index, $ids) {
                $ids[] = (string) $message->id;
                $this->postSequential($channel, $payloads, $index + 1, $ids);
            })
            ->otherwise(fn () => null);
    }

    /** @param array{key:string, title:string, description:string} $payload */
    private function buildEmbed(array $payload): Embed
    {
        $embed = new Embed($this->discord);
        $embed->setTitle($payload['title']);

        if (! empty($payload['description'])) {
            $embed->setDescription($payload['description']);
        }

        return $embed;
    }
}
