<?php

declare(strict_types=1);

/** Parse bounded platform directory controls independently of operator identity. */

namespace App\Data\Admin;

/** Invalid GET controls are ignored and named without redirect loops or raw-value reflection. */
final readonly class PlatformTeamSelection
{
    /**
     * @param string $q Trimmed literal search of at most 100 characters.
     * @param int $page Positive one-based page with at most nine digits.
     * @param list<string> $rejected Invalid field names.
     */
    private function __construct(public string $q, public int $page, public array $rejected)
    {
    }

    /**
     * @param array<array-key, mixed> $input Untrusted direct query or parsed return hint.
     * @return self Normalized directory selection with finite controls.
     */
    public static function fromInput(array $input): self
    {
        $rejected = [];
        $q = $input['q'] ?? '';
        if (!is_string($q) || mb_strlen(trim($q)) > 100 || preg_match('/[\x00-\x1f\x7f]/', $q) === 1) {
            $q = '';
            $rejected[] = 'q';
        }
        $page = $input['page'] ?? '1';
        if (!is_string($page) || preg_match('/^[1-9][0-9]{0,8}$/D', $page) !== 1) {
            $page = '1';
            $rejected[] = 'page';
        }

        return new self(trim($q), (int) $page, $rejected);
    }
}
