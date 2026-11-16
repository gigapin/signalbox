<?php

declare(strict_types=1);

/** Bind a prepared delivery to its verified destination without persisting another copy of its credentials. */

namespace App\Services\Notifications;

use App\Contracts\Notifications\ChannelConfiguration;
use App\Enums\Security\KeyPurpose;
use App\Services\Security\PurposeKeys;

/** Signing-key rotation keeps the same webhook destination; destination or credential changes invalidate pending deliveries. */
final readonly class ChannelDestinationFingerprint
{
    /** @param PurposeKeys $keys Dedicated destination keys, including configured historical readers. */
    public function __construct(private PurposeKeys $keys)
    {
    }

    /**
     * @param ChannelConfiguration $configuration Validated family values, including private destination credentials.
     * @return string HMAC-SHA256 of the canonical family/field map, excluding only webhook signing-key rotation.
     */
    public function for(#[\SensitiveParameter] ChannelConfiguration $configuration): string
    {
        return $this->keys->digest(KeyPurpose::ChannelDestination, $this->message($configuration));
    }

    /**
     * @param ChannelConfiguration $configuration Current validated destination and credentials.
     * @return list<string> Equivalent destination digests across the configured rotation window.
     */
    public function candidates(#[\SensitiveParameter] ChannelConfiguration $configuration): array
    {
        return $this->keys->candidates(KeyPurpose::ChannelDestination, $this->message($configuration));
    }


    /**
     * @param string $stored Frozen delivery destination, never a provider credential.
     * @param ChannelConfiguration $configuration Current validated destination and credentials.
     * @return bool Whether a frozen destination matches the current configuration under a configured key.
     */
    public function matches(string $stored, #[\SensitiveParameter] ChannelConfiguration $configuration): bool
    {
        return $this->keys->matches($stored, KeyPurpose::ChannelDestination, $this->message($configuration));
    }

    /**
     * @param ChannelConfiguration $configuration Validated configuration whose fields have canonical types.
     * @return string Stable canonical destination input; signing-secret rotation preserves this identity.
     */
    private function message(#[\SensitiveParameter] ChannelConfiguration $configuration): string
    {
        $values = $configuration->toArray();
        unset($values['signing_secret']);
        ksort($values);

        return $configuration->type()->value.serialize($values);
    }

}
