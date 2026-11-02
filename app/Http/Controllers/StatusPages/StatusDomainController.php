<?php

declare(strict_types=1);

/** Translate authorized browser claim changes into domain actions; TLS authorization is stateless and read-only. */

namespace App\Http\Controllers\StatusPages;

use App\Actions\StatusPages\ManageStatusDomains;
use App\Exceptions\StatusPageRejected;
use App\Http\Controllers\Controller;
use App\Http\Requests\StatusPages\StatusDomainRequest;
use App\Models\StatusPage;
use App\Services\StatusPages\StatusDomainNames;
use App\Services\StatusPages\StatusDomainRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** The host guard restricts the ask endpoint's raw peer before this controller or any session middleware runs. */
final class StatusDomainController extends Controller
{
    /** @param ManageStatusDomains $domains Freshly authorized, fenced claim changes and verification. */
    public function __construct(private readonly ManageStatusDomains $domains)
    {
    }

    /**
     * @param StatusDomainRequest $request Authorized bounded hostname.
     * @param int $team Route tenant.
     * @param StatusPage $statusPage Page scoped to that tenant.
     * @return RedirectResponse Settings page with a generic success message.
     * @throws ValidationException When hostname rules refuse the change.
     */
    public function update(StatusDomainRequest $request, int $team, StatusPage $statusPage): RedirectResponse
    {
        $value = $request->validated('domain');

        try {
            $this->domains->save($this->authenticatedUser($request), $team, $statusPage->id, is_string($value) ? $value : null);
        } catch (StatusPageRejected $error) {
            throw ValidationException::withMessages(['domain' => $error->getMessage()]);
        }

        return back()->with('status', 'status-domain-saved');
    }

    /**
     * @param Request $request Verified managing user; the action reauthorizes before and after DNS.
     * @param int $team Route tenant.
     * @param StatusPage $statusPage Tenant-bound page.
     * @return RedirectResponse Settings page once proof is accepted.
     * @throws ValidationException When proof is absent or another live association owns the name.
     */
    public function verify(Request $request, int $team, StatusPage $statusPage): RedirectResponse
    {
        if (!$this->domains->verify($this->authenticatedUser($request), $team, $statusPage->id)) {
            throw ValidationException::withMessages(['domain' => 'The DNS proof could not be confirmed or this hostname is already active on another page.']);
        }

        return back()->with('status', 'status-domain-verified');
    }

    /**
     * @param Request $request Verified manager; the action authorizes under lock.
     * @param int $team Route tenant.
     * @param StatusPage $statusPage Tenant-bound page.
     * @return RedirectResponse Settings page after immediate removal.
     */
    public function destroy(Request $request, int $team, StatusPage $statusPage): RedirectResponse
    {
        $this->domains->save($this->authenticatedUser($request), $team, $statusPage->id, null);

        return back()->with('status', 'status-domain-removed');
    }

    /**
     * @param Request $request Internal raw-peer-authorized request containing only a hostname.
     * @param StatusDomainNames $names Shared canonical/reserved-name rules.
     * @param StatusDomainRegistry $registry Current public association, never a DNS check inside the TLS handshake.
     * @return Response 204 permits certificate management; 404 denies, without page or tenant metadata.
     */
    public function allow(Request $request, StatusDomainNames $names, StatusDomainRegistry $registry): Response
    {
        // Check again at the actual route, including any path decoding performed after global host admission.
        abort_unless($names->allowsTlsPeer($request->server('REMOTE_ADDR')), 403);
        $value = $request->query('domain');
        abort_unless(is_string($value) && strlen($value) <= 242, 404);

        try {
            $hostname = $names->normalize($value);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        abort_unless($registry->allowsCertificate($hostname), 404);

        return response('', 204);
    }
}
