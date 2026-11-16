<?php

declare(strict_types=1);

/** Freeze the versioned HKDF labels used by persistent cryptographic protocols. */

namespace App\Enums\Security;

/** Labels are protocol identifiers: changing one requires a new explicit migration. */
enum KeyPurpose: string
{
    /** Native Laravel encryption for casts, cookies and Fortify secrets. */
    case Encryption = 'laravel-encryption';
    /** Framework URL signatures, separated from encryption and persisted digests. */
    case SignedUrls = 'signed-urls';
    /** Case-insensitive subscriber lookup within a page. */
    case SubscriberEmail = 'subscriber-email';
    /** Consent to the current password of one page. */
    case SubscriberConsent = 'subscriber-consent';
    /** Visitor session proof for one protected page. */
    case PageSession = 'page-session';
    /** Short verification code bound to a notification channel. */
    case ChannelVerification = 'channel-verification';
    /** Frozen notification destination, excluding webhook signing-key rotation. */
    case ChannelDestination = 'channel-destination';
}
