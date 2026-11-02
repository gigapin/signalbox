<?php

declare(strict_types=1);

/** Accept subscription requests and explicit confirmations of signed mailbox capabilities. */

namespace App\Http\Controllers\StatusPages;

use App\Http\Controllers\Controller;
use App\Http\Requests\StatusPages\SubscribeStatusPageRequest;
use App\Services\StatusPages\PublicStatusPages;
use App\Services\StatusPages\StatusSubscriptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** No route exposes recipient email, existing consent or protected page content. */
final class StatusSubscriptionController extends Controller
{
    /**
     * @param PublicStatusPages $pages Explicit public page discovery boundary.
     * @param StatusSubscriptions $subscriptions Transactional mailbox consent and outbox preparation.
     */
    public function __construct(private readonly PublicStatusPages $pages, private readonly StatusSubscriptions $subscriptions)
    {
    }

    /**
     * @param SubscribeStatusPageRequest $request Bounded email and visitor session.
     * @param string $slug Public page address.
     * @return RedirectResponse Identical acknowledgement for pending, active and newly created recipients.
     */
    public function store(SubscribeStatusPageRequest $request, string $slug): RedirectResponse
    {
        $page = $this->pages->find($slug);
        abort_if($page === null, 404);
        $this->subscriptions->subscribe($page, $request->session(), $request->string('email')->toString());

        return redirect()->route('status-pages.public', ['slug' => $slug])->with('status', 'status-subscription-requested');
    }

    /**
     * Signed middleware authenticates all route coordinates before lookup. GET is inert for email scanners.
     *
     * @param Request $request Signed GET or CSRF-protected POST.
     * @param int $teamId Signed tenant identity.
     * @param int $pageId Signed page identity.
     * @param int $subscriberId Signed recipient identity.
     * @param string $generation Revocable consent generation.
     * @param string $action Verify or unsubscribe, constrained by the route.
     * @return Response Generic confirmation form or completion, containing no email or page data.
     */
    public function action(Request $request, int $teamId, int $pageId, int $subscriberId, string $generation, string $action): Response
    {
        $commit = $request->isMethod('POST');
        $this->subscriptions->act($teamId, $pageId, $subscriberId, $generation, $action, $commit);

        return Inertia::render('StatusPages/Subscription', [
            'action' => $action,
            'actionUrl' => $request->getRequestUri(),
            'completed' => $commit,
        ]);
    }
}
