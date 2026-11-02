<?php

declare(strict_types=1);

/** Select metadata independently of visitor/session state and cached page content. */

namespace App\Services\StatusPages;

use App\Data\StatusPages\StatusPageMetadata;
use App\Models\StatusPage;
use LogicException;

/** Canonicals use the configured primary address: DNS ownership alone cannot promise a working TLS alias. */
final class StatusPageDocuments
{
    /**
     * @param StatusPage $page Live published page, already admitted by visibility and origin checks.
     * @return StatusPageMetadata Public metadata, or generic restricted metadata for any password-protected page.
     */
    public function page(StatusPage $page): StatusPageMetadata
    {
        if ($page->password_hash !== null) {
            return $this->restricted('Protected status page', 'Enter the page password to read this status page.');
        }

        $description = $page->description ?? 'Service status, availability history and published incident updates for '.$page->title.'.';

        return new StatusPageMetadata(
            $this->line($page->title).' | '.$this->siteName(),
            $this->line($description),
            StatusPageMetadata::PUBLIC_ROBOTS,
            $this->baseUrl().'/status/'.$page->slug,
            $this->siteName(),
        );
    }

    /**
     * @param string $title Generic form title identifying no team, subscriber or protected page.
     * @param string $description Generic form purpose, excluding capability URLs and mailbox data.
     * @return StatusPageMetadata Restricted metadata with neither canonical nor social URL.
     */
    public function restricted(string $title, string $description = 'Manage status page email updates.'): StatusPageMetadata
    {
        return new StatusPageMetadata(
            $this->line($title).' | '.$this->siteName(),
            $this->line($description),
            StatusPageMetadata::PRIVATE_ROBOTS,
            null,
            $this->siteName(),
        );
    }

    /** @return string Configured product name as plain single-line text. */
    private function siteName(): string
    {
        return $this->line((string) config('app.name'));
    }

    /**
     * @param string $text Literal page text; HTML-sensitive characters remain literal until rendering.
     * @return string Trimmed text with whitespace collapsed, preserving its meaning and UTF-8 characters.
     */
    private function line(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @return string Configured primary HTTP(S) base URL without a trailing slash.
     * @throws LogicException When configuration cannot form a safe canonical; request headers are never a fallback.
     */
    private function baseUrl(): string
    {
        $url = rtrim((string) config('app.url'), '/');
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['host'], $parts['scheme'])
            || !in_array($parts['scheme'], ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f<>"\\\\]/', $url) === 1) {
            throw new LogicException('The configured application URL cannot form a public canonical.');
        }

        return $url;
    }
}
