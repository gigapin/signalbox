<?php

declare(strict_types=1);

/** Hold the address of an email channel. */

namespace App\Data\Notifications;

use App\Contracts\Notifications\ChannelConfiguration;
use App\Enums\Notifications\ChannelType;

/** The address is personal data rather than a credential: managers may see it, other members see it masked. */
final readonly class EmailChannelConfiguration implements ChannelConfiguration
{
    /** @param string $address Lowercase RFC address, at most 254 bytes. */
    public function __construct(public string $address)
    {
    }

    /** @return ChannelType Always email. */
    public function type(): ChannelType
    {
        return ChannelType::Email;
    }

    /** @return array{address: string} Persisted address. */
    public function toArray(): array
    {
        return ['address' => $this->address];
    }

    /** @return string First character of the local part, a mask and the full domain. */
    public function hint(): string
    {
        [$local, $domain] = explode('@', $this->address, 2);

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }

    /** @return array{address: string} The address, which is not a secret. */
    public function editable(): array
    {
        return $this->toArray();
    }

    /**
     * @param ChannelConfiguration $other Configuration compared field by field.
     * @return bool Whether both are email channels to the same address.
     */
    public function sameAs(ChannelConfiguration $other): bool
    {
        return $other instanceof self && $other->address === $this->address;
    }
}
