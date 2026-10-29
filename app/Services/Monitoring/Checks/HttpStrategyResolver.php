<?php

declare(strict_types=1);

/** Resolve implemented HTTP-family variants without falling back for unsupported monitor types. */

namespace App\Services\Monitoring\Checks;

use App\Contracts\Monitoring\HttpResponseStrategy;
use App\Contracts\Monitoring\MonitorConfiguration;
use App\Data\Monitoring\HttpMonitorConfiguration;
use App\Data\Monitoring\KeywordMonitorConfiguration;
use App\Enums\Monitoring\MonitorType;
use InvalidArgumentException;

/** Validate the enum and concrete DTO together before any outbound work is prepared. */
final readonly class HttpStrategyResolver
{
    /**
     * @param HttpStatusStrategy $http Status-only evaluator.
     * @param KeywordStrategy $keyword Status and exact-text evaluator.
     */
    public function __construct(private HttpStatusStrategy $http, private KeywordStrategy $keyword)
    {
    }

    /**
     * @param MonitorConfiguration $configuration Private validated variant.
     * @return HttpResponseStrategy Explicitly registered evaluator for that concrete variant.
     * @throws InvalidArgumentException When the type is unsupported or its DTO is inconsistent.
     */
    public function resolve(#[\SensitiveParameter] MonitorConfiguration $configuration): HttpResponseStrategy
    {
        return match (true) {
            $configuration instanceof HttpMonitorConfiguration && $configuration->type() === MonitorType::Http => $this->http,
            $configuration instanceof KeywordMonitorConfiguration && $configuration->type() === MonitorType::Keyword => $this->keyword,
            default => throw new InvalidArgumentException('Unsupported or inconsistent HTTP checker configuration.'),
        };
    }

    /**
     * @param MonitorConfiguration $configuration Variant accepted by the same explicit resolver.
     * @return HttpMonitorConfiguration Shared HTTP transport settings.
     * @throws InvalidArgumentException When the variant is not supported.
     */
    public function transport(#[\SensitiveParameter] MonitorConfiguration $configuration): HttpMonitorConfiguration
    {
        $this->resolve($configuration);

        if ($configuration instanceof HttpMonitorConfiguration) {
            return $configuration;
        }

        if ($configuration instanceof KeywordMonitorConfiguration) {
            return $configuration->http;
        }

        throw new InvalidArgumentException('Unsupported HTTP transport configuration.');
    }
}
