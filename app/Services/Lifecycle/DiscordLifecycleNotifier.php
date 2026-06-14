<?php

namespace App\Services\Lifecycle;

use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Embed\Embed;

/**
 * Posts births to the births channel and eulogies to the eulogy channel as a rich newspaper-style
 * embed, with the real mention ping (if any) on a plain content line above it. One-shot posts (no
 * edit-in-place). Entirely best-effort: null client / missing channel / send failure all no-op.
 */
class DiscordLifecycleNotifier implements LifecycleNotifier
{
    public function __construct(
        private ?Discord $discord,
        private ?string $birthsChannelId,
        private ?string $eulogyChannelId,
    ) {}

    public function publishBirth(array $payload): void
    {
        $this->post($this->birthsChannelId, $payload);
    }

    public function publishEulogy(array $payload): void
    {
        $this->post($this->eulogyChannelId, $payload);
    }

    private function post(?string $channelId, array $payload): void
    {
        if (! $this->discord || ! $channelId) {
            return;
        }

        try {
            $channel = $this->discord->getChannel($channelId);
            if (! $channel) {
                return;
            }

            $embed = new Embed($this->discord);
            $embed->setTitle($this->trim($payload['title'], 256));
            $embed->setDescription($this->trim($payload['description'], 4096));
            $embed->setColor($payload['color'] ?? 0x2B2D31);
            if (! empty($payload['footer'])) {
                $embed->setFooter($payload['footer']);
            }
            foreach ($payload['fields'] ?? [] as $field) {
                $embed->addFieldValues($field['name'], $field['value'], true);
            }

            $builder = MessageBuilder::new()->addEmbed($embed);
            if (! empty($payload['ping'])) {
                $builder->setContent($payload['ping']);
            }

            $channel->sendMessage($builder)->otherwise(fn () => null);
        } catch (\Throwable) {
            // best-effort: never propagate to caller
        }
    }

    private function trim(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }
}
