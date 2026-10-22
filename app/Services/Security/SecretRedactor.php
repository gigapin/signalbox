<?php

declare(strict_types=1);

/** Remove credentials and private configuration from values that leave the protected configuration boundary. */

namespace App\Services\Security;

/** Redaction is lossy on purpose: outputs keep only what an operator needs to recognize the target. */
final class SecretRedactor
{
    /** @var string Placeholder replacing every removed value. */
    public const string PLACEHOLDER = '[REDACTED]';

    /** @var list<string> Lowercase header names whose values describe content negotiation rather than credentials. */
    private const array PUBLIC_HEADERS = ['accept', 'accept-language', 'content-type', 'user-agent'];

    /** @var string Context keys whose values are replaced entirely, matched case-insensitively. */
    private const string SENSITIVE_KEY = '/pass|secret|token|authorization|cookie|api[_-]?key|signature|credential|header|body/i';

    /** @var string Context keys holding URLs, whose query values are redacted while the target stays visible. */
    private const string URL_KEY = '/(^|_)(url|uri|location)$/i';

    /** @var string Absolute URLs embedded in free text such as transport error messages. */
    private const string URL_PATTERN = '#\b[a-z][a-z0-9+.-]*://[^\s"\'<>]+#i';

    /**
     * @param string $url Absolute URL, possibly with userinfo, query or fragment.
     * @return string Scheme, host, port and path with every query value redacted; userinfo and fragment removed.
     */
    public function url(#[\SensitiveParameter] string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return self::PLACEHOLDER;
        }

        $result = $parts['scheme'].'://'.$parts['host'];
        $result .= isset($parts['port']) ? ':'.$parts['port'] : '';
        $result .= $parts['path'] ?? '';

        if (isset($parts['query']) && $parts['query'] !== '') {
            $pairs = array_map(
                static fn (string $pair): string => str_contains($pair, '=')
                    ? explode('=', $pair, 2)[0].'='.self::PLACEHOLDER
                    : self::PLACEHOLDER,
                explode('&', $parts['query']),
            );

            $result .= '?'.implode('&', $pairs);
        }

        return $result;
    }

    /**
     * @param array<string, string> $headers Lowercase header names mapped to private values.
     * @return array<string, string> Same names; values kept only for content-negotiation headers.
     */
    public function headers(#[\SensitiveParameter] array $headers): array
    {
        $redacted = [];

        foreach ($headers as $name => $value) {
            $redacted[$name] = in_array(strtolower($name), self::PUBLIC_HEADERS, true) ? $value : self::PLACEHOLDER;
        }

        return $redacted;
    }

    /**
     * @param string|null $body Private request body; null differs from an empty body.
     * @return array{present: bool, bytes: int} Presence and size in bytes, never content.
     */
    public function body(#[\SensitiveParameter] ?string $body): array
    {
        return ['present' => $body !== null, 'bytes' => $body === null ? 0 : strlen($body)];
    }

    /**
     * @param string $message Free text such as a cURL error, which may quote the requested URL.
     * @param list<string> $secrets Known private values to remove verbatim, such as header values or tokens.
     * @return string Text without the known secrets and with every embedded URL redacted.
     */
    public function message(#[\SensitiveParameter] string $message, #[\SensitiveParameter] array $secrets = []): string
    {
        $secrets = array_values(array_filter($secrets, static fn (string $secret): bool => $secret !== ''));

        // Longer values first, so a secret containing another one is not left partially visible.
        usort($secrets, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        $message = str_replace($secrets, self::PLACEHOLDER, $message);

        return (string) preg_replace_callback(
            self::URL_PATTERN,
            fn (array $match): string => $this->url($match[0]),
            $message,
        );
    }

    /**
     * @param array<array-key, mixed> $context Structured log or diagnostic data, possibly nested.
     * @return array<array-key, mixed> Copy with sensitive keys replaced, URL keys and strings redacted; objects are kept.
     */
    public function context(#[\SensitiveParameter] array $context): array
    {
        $redacted = [];

        foreach ($context as $key => $value) {
            $isNamed = is_string($key);

            if ($isNamed && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                $redacted[$key] = self::PLACEHOLDER;
            } elseif ($isNamed && is_string($value) && preg_match(self::URL_KEY, $key) === 1) {
                $redacted[$key] = $this->url($value);
            } elseif (is_array($value)) {
                $redacted[$key] = $this->context($value);
            } elseif (is_string($value)) {
                $redacted[$key] = $this->message($value);
            } else {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }
}
