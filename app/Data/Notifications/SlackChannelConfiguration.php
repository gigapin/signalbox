<?php

declare(strict_types=1);

/** Hold the incoming webhook of a Slack channel. */

namespace App\Data\Notifications;

use App\Contracts\Notifications\ChannelConfiguration;
use App\Enums\Notifications\ChannelType;

/** A Slack incoming webhook URL is itself the credential: anyone holding it can post to the channel. */
final readonly class SlackChannelConfiguration implements ChannelConfiguration
{
    /** @param string $webhookUrl HTTPS URL on hooks.slack.com under /services/. */
    public function __construct(public string $webhookUrl)
    {
    }

    /** @return ChannelType Always Slack. */
    public function type(): ChannelType
    {
        return ChannelType::Slack;
    }

    /** @return array{webhook_url: string} Persisted secret URL. */
    public function toArray(): array
    {
        return ['webhook_url' => $this->webhookUrl];
    }

    /** @return string Fixed host and path prefix with the last four characters of the URL. */
    public function hint(): string
    {
        return 'hooks.slack.com/services/…'.substr($this->webhookUrl, -4);
    }

    /** @return array<string, string> Nothing: the URL is secret and is replaced, never shown. */
    public function editable(): array
    {
        return [];
    }

    /**
     * @param ChannelConfiguration $other Configuration compared field by field.
     * @return bool Whether both post to the same webhook.
     */
    public function sameAs(ChannelConfiguration $other): bool
    {
        return $other instanceof self && hash_equals($other->webhookUrl, $this->webhookUrl);
    }
}
