<?php

declare(strict_types=1);

/** Parse bounded read controls shared by the maintenance and delivery lists. */

namespace App\Data\Operations;

/** Invalid GET values are dropped and reported by name without a redirect loop. */
final readonly class OperationalListFilter
{
    /**
     * @param string $q Trimmed search term, at most 100 characters.
     * @param string|null $state Accepted domain state or no restriction.
     * @param int $page One-based page with at most nine digits.
     * @param list<string> $rejected Invalid input names, never their values.
     */
    private function __construct(public string $q, public ?string $state, public int $page, public array $rejected)
    {
    }

    /**
     * @param array<array-key, mixed> $input Raw query controls.
     * @param list<string> $states States offered by this domain list.
     * @return self Accepted selection independent of the route tenant.
     */
    public static function fromInput(array $input, array $states): self
    {
        $rejected = [];
        $q = $input['q'] ?? '';
        if (!is_string($q) || mb_strlen($q) > 100 || preg_match('/[\x00-\x1f\x7f]/', $q) === 1) {
            $q = '';
            $rejected[] = 'q';
        }

        $state = $input['state'] ?? null;
        if ($state !== null && (!is_string($state) || !in_array($state, $states, true))) {
            $state = null;
            $rejected[] = 'state';
        }

        $page = $input['page'] ?? '1';
        if (!is_string($page) || preg_match('/^[1-9][0-9]{0,8}$/D', $page) !== 1) {
            $page = '1';
            $rejected[] = 'page';
        }

        return new self(trim($q), $state, (int) $page, $rejected);
    }
}
