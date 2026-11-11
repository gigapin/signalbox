<?php

declare(strict_types=1);

/** Build authorized incident-list and monitor return links without accepting arbitrary redirect destinations. */

namespace App\Http\Navigation;

use App\Data\Incidents\IncidentListSelection;
use Illuminate\Http\Request;

/** Navigation context never grants membership or alters a mutation payload. */
final readonly class IncidentListReturn
{
    /**
     * @param ListReturn $links Shared bounded list-hint parser.
     * @param MonitorListReturn $monitors Existing monitor-list normalization.
     */
    public function __construct(private ListReturn $links, private MonitorListReturn $monitors)
    {
    }

    /**
     * @param Request $request Authorized read carrying optional query hints.
     * @param int $teamId Route team.
     * @return string Same-team incident list with accepted filters and page.
     */
    public function resolve(Request $request, int $teamId): string
    {
        return $this->links->resolve($request, '/teams/'.$teamId.'/incidents', static function (array $input): array {
            $filter = IncidentListSelection::fromInput($input)->filter;
            $accepted = $filter->toArray();
            if ($filter->page > 1) {
                $accepted['page'] = $filter->page;
            }

            return $accepted;
        });
    }

    /**
     * @param Request $request Authorized incident read with optional monitor-list context.
     * @param int $teamId Route team.
     * @param int $monitorId Bound incident's actual monitor, never supplied by the hint.
     * @return string Canonical monitor detail preserving only its normalized list return.
     */
    public function monitor(Request $request, int $teamId, int $monitorId): string
    {
        $base = '/teams/'.$teamId.'/monitors';
        $list = $this->monitors->resolve($request, $teamId, 'monitorReturnTo');
        $path = $base.'/'.$monitorId;

        return $list === $base ? $path : $path.'?'.http_build_query(['returnTo' => $list], '', '&', PHP_QUERY_RFC3986);
    }
}
