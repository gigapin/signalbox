<?php

declare(strict_types=1);

/** Retain accepted platform directory search without using a raw redirect destination. */

namespace App\Http\Navigation;

use App\Data\Admin\PlatformTeamSelection;
use Illuminate\Http\Request;

/** Operator authorization remains on every route; a return hint carries no authority. */
final readonly class PlatformDirectoryReturn
{
    /** @param ListReturn $links Existing bounded same-path and duplicate-key checks. */
    public function __construct(private ListReturn $links)
    {
    }

    /**
     * @param Request $request Operator read or mutation with an optional query-only return hint.
     * @return string Canonical platform directory URL, with accepted search and page only.
     */
    public function resolve(Request $request): string
    {
        return $this->links->resolve($request, '/admin/teams', static function (array $input): array {
            $filter = PlatformTeamSelection::fromInput($input);

            return ['q' => $filter->q, 'page' => $filter->page > 1 ? $filter->page : null];
        });
    }

    /**
     * @param Request $request Current operator request; body fields cannot change navigation.
     * @param int $teamId Actual route target.
     * @return string That team's operator detail with the normalized directory context, if present.
     */
    public function detail(Request $request, int $teamId): string
    {
        $path = '/admin/teams/'.$teamId;
        $list = $this->resolve($request);

        return $list === '/admin/teams' ? $path : $path.'?'.http_build_query(['returnTo' => $list], '', '&', PHP_QUERY_RFC3986);
    }
}
