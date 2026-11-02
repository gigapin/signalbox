<?php

declare(strict_types=1);

/** Carry one explicit metadata projection for initial HTML and subsequent Inertia navigation. */

namespace App\Data\StatusPages;

/** A null canonical denotes a restricted document, with no page-specific social preview or indexable URL. */
final readonly class StatusPageMetadata
{
    /** @var string Indexing policy for public, passwordless page content. */
    public const string PUBLIC_ROBOTS = 'index, follow';

    /** @var string Restricted pages must be excluded before JavaScript runs, including after password unlock. */
    public const string PRIVATE_ROBOTS = 'noindex, nofollow, nosnippet';

    /**
     * @param string $title Single-line document title, including product name.
     * @param string $description Plain single-line description, never HTML markup.
     * @param string $robots Explicit public or restricted indexing policy.
     * @param string|null $canonicalUrl Stable configured public URL, without request query parameters.
     * @param string $siteName Configured product name for public social previews.
     */
    public function __construct(
        public string $title,
        public string $description,
        public string $robots,
        public ?string $canonicalUrl,
        public string $siteName,
    ) {
    }

    /** @return array{title: string, description: string, robots: string, canonicalUrl: string|null, siteName: string} Shared shape for Blade and React, containing no rendered HTML. */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'robots' => $this->robots,
            'canonicalUrl' => $this->canonicalUrl,
            'siteName' => $this->siteName,
        ];
    }
}
