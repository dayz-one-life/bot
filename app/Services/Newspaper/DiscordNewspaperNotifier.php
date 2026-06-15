<?php

namespace App\Services\Newspaper;

use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Embed\Embed;

/**
 * Posts a Tribune issue as ONE message carrying all section embeds (masthead first). One-shot post
 * (immutable back issues — no edit-in-place). No content line => never @-mentions. Best-effort:
 * null client / missing channel / send failure all no-op.
 */
class DiscordNewspaperNotifier implements NewspaperNotifier
{
    public function __construct(
        private ?Discord $discord,
        private ?string $channelId,
    ) {}

    public function publish(array $embeds): void
    {
        if (! $this->discord || ! $this->channelId || $embeds === []) {
            return;
        }

        try {
            $channel = $this->discord->getChannel($this->channelId);
            if (! $channel) {
                return;
            }

            $builder = MessageBuilder::new();
            foreach ($embeds as $payload) {
                $embed = new Embed($this->discord);
                $embed->setTitle($this->trim($payload['title'], 256));
                $embed->setDescription($this->trim($payload['description'], 4096));
                $embed->setColor($payload['color'] ?? 0xC9B037);
                if (! empty($payload['footer'])) {
                    $embed->setFooter($payload['footer']);
                }
                $builder->addEmbed($embed);
            }

            $channel->sendMessage($builder)->otherwise(fn () => null);
        } catch (\Throwable) {
            // best-effort: never propagate
        }
    }

    private function trim(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }
}
