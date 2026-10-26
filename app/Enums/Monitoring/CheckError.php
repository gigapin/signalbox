<?php

declare(strict_types=1);

/** Classify check failures without propagating private transport messages or response content. */

namespace App\Enums\Monitoring;

/** These errors are observed check outcomes; programming failures remain exceptions. */
enum CheckError: string
{
    /** The configured or redirected destination is invalid. */
    case InvalidDestination = 'invalid_destination';
    /** The destination uses a forbidden local hostname. */
    case LocalName = 'local_name';
    /** An address is denied by the outbound policy. */
    case BlockedAddress = 'blocked_address';
    /** The destination could not be resolved. */
    case ResolutionFailed = 'resolution_failed';
    /** The redirect chain exceeded the configured hop bound. */
    case RedirectLimit = 'redirect_limit';
    /** A redirect attempted an HTTPS downgrade. */
    case RedirectDowngrade = 'redirect_downgrade';
    /** A redirect would disclose a private request body to another origin. */
    case CrossOriginBody = 'cross_origin_body';
    /** A followed redirect lacked a usable Location header. */
    case InvalidRedirect = 'invalid_redirect';
    /** The cumulative network time budget was exhausted. */
    case Timeout = 'timeout';
    /** The transport could not establish or complete a connection. */
    case Connection = 'connection_failed';
    /** Certificate verification or TLS negotiation failed. */
    case Tls = 'tls_failed';
    /** Decoded response bodies exceeded the cumulative byte cap. */
    case ResponseTooLarge = 'response_too_large';
    /** The final status did not match the configured expectation. */
    case UnexpectedStatus = 'unexpected_status';
    /** The exact keyword presence or absence expectation was not satisfied. */
    case KeywordMismatch = 'keyword_mismatch';
    /** A valid certificate has entered the configured expiry warning window. */
    case TlsExpiring = 'tls_expiring';
    /** The queried name or requested record does not exist. */
    case DnsMissing = 'dns_missing';
    /** The complete observed record set differs from the expected set. */
    case DnsMismatch = 'dns_mismatch';
    /** The DNS server returned an error or an unusable wire response. */
    case DnsResponse = 'dns_response_failed';
    /** A DNS response contains invalid data or an invalid alias chain. */
    case DnsMalformed = 'dns_malformed';

    /** @return string Safe English diagnostic with no destination, credentials or server-provided text. */
    public function message(): string
    {
        return match ($this) {
            self::InvalidDestination => 'The destination is invalid.',
            self::LocalName => 'Local hostnames are not allowed.',
            self::BlockedAddress => 'The destination address is not allowed.',
            self::ResolutionFailed => 'The destination could not be resolved.',
            self::RedirectLimit => 'The redirect limit was exceeded.',
            self::RedirectDowngrade => 'An HTTPS downgrade was refused.',
            self::CrossOriginBody => 'A cross-origin redirect carrying a request body was refused.',
            self::InvalidRedirect => 'The redirect has no usable destination.',
            self::Timeout => 'The network time budget was exhausted.',
            self::Connection => 'The connection failed.',
            self::Tls => 'TLS negotiation or verification failed.',
            self::ResponseTooLarge => 'The response exceeded the permitted size.',
            self::UnexpectedStatus => 'The response status did not match the expectation.',
            self::KeywordMismatch => 'The response text did not match the expectation.',
            self::TlsExpiring => 'The certificate is within the expiry warning window.',
            self::DnsMissing => 'The requested DNS records do not exist.',
            self::DnsMismatch => 'The DNS records did not match the expected set.',
            self::DnsResponse => 'The DNS server did not return a usable response.',
            self::DnsMalformed => 'The DNS response or alias chain is invalid.',
        };
    }
}
