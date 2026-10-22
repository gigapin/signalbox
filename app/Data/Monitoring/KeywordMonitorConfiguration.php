<?php

declare(strict_types=1);

/** Describe a text expectation composed with ordinary HTTP transport settings. */

namespace App\Data\Monitoring;

use App\Contracts\Monitoring\MonitorConfiguration;
use App\Enums\Monitoring\KeywordMode;
use App\Enums\Monitoring\MonitorType;
use InvalidArgumentException;

/** Preserve exact keyword bytes; whitespace may itself be meaningful response content. */
final readonly class KeywordMonitorConfiguration implements MonitorConfiguration
{
    /**
     * @param HttpMonitorConfiguration $http Shared transport and status expectation.
     * @param string $keyword Nonempty exact text; never trimmed or interpreted as a regular expression.
     * @param KeywordMode $mode Whether the text must be present or absent.
     * @throws InvalidArgumentException When the keyword is empty.
     */
    public function __construct(
        public HttpMonitorConfiguration $http,
        public string $keyword,
        public KeywordMode $mode = KeywordMode::Present,
    ) {
        if ($keyword === '') {
            throw new InvalidArgumentException('Keyword configuration requires nonempty text.');
        }
    }

    /** @return MonitorType Keyword observation identity. */
    public function type(): MonitorType
    {
        return MonitorType::Keyword;
    }
}
