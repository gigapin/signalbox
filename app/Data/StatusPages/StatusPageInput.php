<?php

declare(strict_types=1);

/** Carry a submitted status page, independently of the transport that received it. */

namespace App\Data\StatusPages;

/** Values are shaped but not yet accepted: ManageStatusPages applies the domain rules under the Team lock. */
final readonly class StatusPageInput
{
    /**
     * @param string $slug Public address segment.
     * @param string $title Page heading.
     * @param string|null $description Optional introduction.
     * @param StatusPageTheme $theme Appearance.
     * @param bool $isPublic Whether the public address answers; false keeps the page a draft.
     * @param string|null $password New plaintext password; null keeps the current state.
     * @param bool $removePassword Whether to remove the current password.
     * @param list<array{name: string, monitor_ids: list<int>}> $components Components in display order.
     */
    public function __construct(
        public string $slug,
        public string $title,
        public ?string $description,
        public StatusPageTheme $theme,
        public bool $isPublic,
        #[\SensitiveParameter]
        public ?string $password,
        public bool $removePassword,
        public array $components,
    ) {
    }
}
