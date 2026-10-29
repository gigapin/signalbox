<?php

declare(strict_types=1);

/** Hold the phone number of the declared SMS stub channel. */

namespace App\Data\Notifications;

use App\Contracts\Notifications\ChannelConfiguration;
use App\Enums\Notifications\ChannelType;

/** No carrier is integrated: the number is stored for the stub adapter only. */
final readonly class SmsChannelConfiguration implements ChannelConfiguration
{
    /** @param string $phone E.164 number such as `+393331234567`. */
    public function __construct(public string $phone)
    {
    }

    /** @return ChannelType Always SMS. */
    public function type(): ChannelType
    {
        return ChannelType::Sms;
    }

    /** @return array{phone: string} Persisted number. */
    public function toArray(): array
    {
        return ['phone' => $this->phone];
    }

    /** @return string Plus sign, a mask and the last four digits. */
    public function hint(): string
    {
        return '+•••'.substr($this->phone, -4);
    }

    /** @return array{phone: string} The number, which is not a credential. */
    public function editable(): array
    {
        return $this->toArray();
    }

    /**
     * @param ChannelConfiguration $other Configuration compared field by field.
     * @return bool Whether both text the same number.
     */
    public function sameAs(ChannelConfiguration $other): bool
    {
        return $other instanceof self && $other->phone === $this->phone;
    }
}
