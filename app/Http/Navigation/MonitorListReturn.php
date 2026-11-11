<?php

declare(strict_types=1);

/** Reconstruct GET-only return context to the authorized team's monitor list. */

namespace App\Http\Navigation;

use App\Data\Monitoring\MonitorListSelection;
use Illuminate\Http\Request;

/** A navigation hint never grants access, carries form data, or acts as a raw redirect destination. */
final readonly class MonitorListReturn
{
    /** @param ListReturn $links Shared bounded same-path navigation parser. */
    public function __construct(private ListReturn $links)
    {
    }

    /**
     * @param Request $request Request with optional untrusted navigation context.
     * @param int $teamId Authorized route team, independent of query input.
     * @param string $parameter Internal hint key; monitor pages use returnTo by default.
     * @return string Canonical same-team monitor list URL.
     */
    public function resolve(Request $request, int $teamId, string $parameter = 'returnTo'): string
    {
        return $this->links->resolve($request, '/teams/'.$teamId.'/monitors', static function (array $input): array {
            $filter = MonitorListSelection::fromInput($input)->filter;
            $accepted = $filter->toArray();
            if ($filter->page > 1) {
                $accepted['page'] = $filter->page;
            }

            return $accepted;
        }, $parameter);
    }
}
