<?php

declare(strict_types=1);

/** Bind a prepared delivery to its verified destination without persisting another copy of its credentials. */

namespace App\Services\Notifications;

use App\Contracts\Notifications\ChannelConfiguration;
use Illuminate\Contracts\Config\Repository;

/** Signing-key rotation keeps the same webhook destination; destination or credential changes invalidate pending deliveries. */
final readonly class ChannelDestinationFingerprint
{
    /** @param Repository $config Application key protecting destination fingerprints against offline guessing. */
    public function __construct(private Repository $config)
    {
    }

    /**
     * @param ChannelConfiguration $configuration Validated family values, including private destination credentials.
     * @return string HMAC-SHA256 of the canonical family/field map, excluding only webhook signing-key rotation.
     */
    public function for(#[\SensitiveParameter] ChannelConfiguration $configuration): string
    {
        $values = $configuration->toArray();
        unset($values['signing_secret']);
        ksort($values);

        return hash_hmac('sha256', $configuration->type()->value.serialize($values), (string) $this->config->get('app.key'));
    }
}
