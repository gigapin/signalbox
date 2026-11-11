<?php

declare(strict_types=1);

/** Parse the platform team directory query string tolerantly. */

namespace App\Http\Requests\Admin;

use App\Data\Admin\PlatformTeamSelection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Like the monitor list, unusable values are ignored rather than failing validation, which on a GET would
 * redirect to the same URL. Authorization is the EnsurePlatformAdmin middleware.
 */
final class ListPlatformTeamsRequest extends FormRequest
{
    /** @var int QUERY_MAX_LENGTH Longest accepted search text, in characters. */
    public const int QUERY_MAX_LENGTH = 100;

    /** @return bool Always true; the route middleware has already admitted only operators. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> No framework rules; the accessors parse each key. */
    public function rules(): array
    {
        return [];
    }

    /** @return string Trimmed search text, or empty when absent, not a string or too long. */
    public function search(): string
    {
        return PlatformTeamSelection::fromInput($this->query->all())->q;
    }

    /** @return int Requested page, or 1 when absent or invalid. */
    public function page(): int
    {
        return PlatformTeamSelection::fromInput($this->query->all())->page;
    }

    /** @return list<string> Invalid bounded control names, without their submitted values. */
    public function rejectedFilters(): array
    {
        return PlatformTeamSelection::fromInput($this->query->all())->rejected;
    }

}
