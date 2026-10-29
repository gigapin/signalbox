<?php

declare(strict_types=1);

/** Generate verification codes and the keyed digests stored in their place. */

namespace App\Services\Notifications;

use Illuminate\Contracts\Config\Repository;

/**
 * A six-digit code has only a million values, so a plain hash could be reversed offline from a database copy.
 * The digest is an HMAC keyed by the application key and bound to the channel identity.
 */
final readonly class ChannelVerificationCodes
{
    /** @param Repository $config Source of the application key. */
    public function __construct(private Repository $config)
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
     * @return string Hexadecimal HMAC-SHA256 digest.
     */
    public function digest(int $channelId, string $code): string
    {
        return hash_hmac('sha256', 'notification-channel-verification:'.$channelId.':'.$code, (string) $this->config->get('app.key'));
    }
}
