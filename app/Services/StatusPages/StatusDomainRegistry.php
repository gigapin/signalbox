<?php

declare(strict_types=1);

/** Resolve a verified hostname from the exclusive registry without caching authorization state. */

namespace App\Services\StatusPages;

use App\Models\Scopes\TeamScope;
use App\Models\StatusPage;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Global discovery reads only a public page at a currently authorized hostname; private settings stay tenant-scoped. */
final class StatusDomainRegistry
{
    /** @param TenantContext $tenants Enforce private management projection ownership independently of its caller. */
    public function __construct(private readonly TenantContext $tenants)
    {
    }

    /**
     * @param string $hostname Strict canonical HTTP hostname, already syntax-checked.
     * @return StatusPage|null Live public page with an unexpired matching association.
     */
    public function publicPage(string $hostname): ?StatusPage
    {
        return $this->active($hostname)->select('status_pages.*')->first();
    }

    /**
     * @param string $hostname Normalized customer hostname from the private TLS admission request.
     * @return bool Whether certificate management is allowed, without loading any page content or password hash.
     */
    public function allowsCertificate(string $hostname): bool
    {
        return $this->active($hostname)->exists();
    }

    /**
     * @param StatusPage $page Page loaded in the authorized route team.
     * @return array{hostname: string, proofName: string, proofValue: string, verifiedUntil: string|null, checkedAt: string|null}|null Explicit management projection, never included in public content.
     */
    public function present(StatusPage $page): ?array
    {
        abort_unless($page->team_id === $this->tenants->requireTeamId(), 404);

        if ($page->custom_domain === null || $page->domain_challenge === null) {
            return null;
        }

        $binding = DB::table('status_domain_bindings')->where('team_id', $page->team_id)->where('status_page_id', $page->id)
            ->where('challenge', $page->domain_challenge)->where('expires_at', '>', CarbonImmutable::now('UTC'))->first(['expires_at']);

        return [
            'hostname' => $page->custom_domain,
            'proofName' => '_signalbox.'.$page->custom_domain,
            'proofValue' => 'signalbox-verification='.$page->domain_challenge,
            'verifiedUntil' => $binding === null ? null : CarbonImmutable::parse($binding->expires_at)->utc()->toIso8601String(),
            'checkedAt' => $page->domain_checked_at?->utc()->toIso8601String(),
        ];
    }

    /**
     * @param string $hostname Canonical hostname.
     * @return Builder<StatusPage> Shared live public authorization predicate for HTTP and TLS admission.
     */
    private function active(string $hostname): Builder
    {
        return StatusPage::withoutGlobalScope(TeamScope::class)
            ->join('status_domain_bindings as binding', 'binding.status_page_id', '=', 'status_pages.id')
            ->where('binding.hostname', $hostname)
            ->where('binding.expires_at', '>', CarbonImmutable::now('UTC'))
            ->where('status_pages.is_public', true);
    }
}
