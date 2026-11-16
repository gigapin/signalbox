<?php

declare(strict_types=1);

/** Generate verification codes and the keyed digests stored in their place. */

namespace App\Services\Notifications;

use App\Enums\Security\KeyPurpose;
use App\Services\Security\PurposeKeys;

/**
 * A six-digit code has only a million values, so a plain hash could be reversed offline from a database copy.
 * The digest uses a dedicated derived key and is bound to the channel identity; historical keys verify during rotation.
 */
final readonly class ChannelVerificationCodes
{
    /** @param PurposeKeys $keys Purpose-separated current and previous verification keys. */
    public function __construct(private PurposeKeys $keys)
    {
    }

    /** @return string Uniformly random six-digit code, zero-padded. */
    public function generate(): string
    {
        return str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * @param int $channelId Channel the code belongs to.
     * @param string $code Plaintext code.
     * @return string Versioned HMAC-SHA256 digest.
     */
    public function digest(int $channelId, #[\SensitiveParameter] string $code): string
    {
        return $this->keys->digest(KeyPurpose::ChannelVerification, 'notification-channel-verification:'.$channelId.':'.$code);
    }

    /**
     * @param string $stored Saved pending digest.
     * @param int $channelId Channel identity binding.
     * @param string $code Submitted code, never logged.
     * @return bool Whether a current or configured historical key authenticates the code.
     */
    public function matches(string $stored, int $channelId, #[\SensitiveParameter] string $code): bool
    {
        return $this->keys->matches($stored, KeyPurpose::ChannelVerification, 'notification-channel-verification:'.$channelId.':'.$code);
    }

}
