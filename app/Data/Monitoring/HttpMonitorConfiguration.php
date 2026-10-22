<?php

declare(strict_types=1);

/** Describe an HTTP observation without executing or authorizing a network request. */

namespace App\Data\Monitoring;

use App\Contracts\Monitoring\MonitorConfiguration;
use App\Enums\Monitoring\HttpMethod;
use App\Enums\Monitoring\MonitorType;
use InvalidArgumentException;

/** Share transport settings with keyword monitors through composition, never inheritance. */
final readonly class HttpMonitorConfiguration implements MonitorConfiguration
{
    /** @var array<string, string> Structurally validated header names and values. */
    public array $headers;

    /**
     * @param string $url Nonempty destination; syntax is validated by M2.02, access by OutboundDestinationGuard.
     * @param HttpMethod $method Application request verb.
     * @param array<array-key, mixed> $headers Header names and string values; encrypted during persistence.
     * @param string|null $body Optional request body; null differs from an empty body.
     * @param int $expectedStatus Expected response code from 100 through 599.
     * @param bool $followRedirects Whether the future protected transport may follow validated redirects.
     * @throws InvalidArgumentException When the structural configuration is inconsistent.
     */
    public function __construct(
        #[\SensitiveParameter]
        public string $url,
        public HttpMethod $method = HttpMethod::GET,
        #[\SensitiveParameter]
        array $headers = [],
        #[\SensitiveParameter]
        public ?string $body = null,
        public int $expectedStatus = 200,
        public bool $followRedirects = false,
    ) {
        if (trim($url) === '' || $expectedStatus < 100 || $expectedStatus > 599) {
            throw new InvalidArgumentException('HTTP configuration requires a destination and a valid response code.');
        }

        $validated = [];

        foreach ($headers as $name => $value) {
            if (!is_string($name) || trim($name) === '' || !is_string($value)) {
                throw new InvalidArgumentException('HTTP headers require nonempty string names and string values.');
            }

            $validated[$name] = $value;
        }

        $this->headers = $validated;
    }

    /** @return MonitorType HTTP observation identity. */
    public function type(): MonitorType
    {
        return MonitorType::Http;
    }
}
