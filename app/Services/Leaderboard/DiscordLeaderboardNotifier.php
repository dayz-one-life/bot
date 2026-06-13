<?php

namespace App\Services\Leaderboard;

use App\Services\State\BotState;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Embed\Embed;

/**
 * Posts the leaderboard embed once and edits it in place thereafter. The live
 * message id + channel are persisted in bot_state; if the stored message is
 * gone or the channel changed, a fresh message is posted and re-stored.
 * Entirely best-effort: null client, missing channel, or any failure no-ops.
 */
class DiscordLeaderboardNotifier implements LeaderboardNotifier
{
    private BotState $state;

    public function __construct(private ?Discord $discord, private ?string $channelId, ?BotState $state = null)
    {
        $this->state = $state ?? new BotState();
    }

    public function publish(array $payload): void
    {
        if (! $this->discord || ! $this->channelId) {
            return;
        }

        try {
            $channel = $this->discord->getChannel($this->channelId);
            if (! $channel) {
                return;
            }

            $embed = $this->buildEmbed($payload);
            $messageId = $this->state->get('leaderboard_message_id');
            $storedChannel = $this->state->get('leaderboard_channel_id');

            if ($messageId && $storedChannel === $this->channelId) {
                $channel->messages->fetch($messageId)->then(
                    fn ($message) => $message->edit(MessageBuilder::new()->addEmbed($embed)),
                    fn () => $this->post($channel, $embed) // fetch failed (deleted) -> repost
                );

                return;
            }

            $this->post($channel, $embed);
        } catch (\Throwable) {
            // best-effort: never propagate to caller
        }
    }

    private function post($channel, Embed $embed): void
    {
        $channel->sendMessage(MessageBuilder::new()->addEmbed($embed))
            ->then(function ($message) {
                $this->state->set('leaderboard_message_id', (string) $message->id);
                $this->state->set('leaderboard_channel_id', (string) $this->channelId);
            })
            ->otherwise(fn () => null);
    }

    private function buildEmbed(array $payload): Embed
    {
        $embed = new Embed($this->discord);
        $embed->setTitle($payload['title']);

        if (! empty($payload['description'])) {
            $embed->setDescription($payload['description']);
        }

        foreach ($payload['fields'] as $field) {
            $embed->addFieldValues($field['name'], $field['value'], false);
        }

        return $embed;
    }
}
