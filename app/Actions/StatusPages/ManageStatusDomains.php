<?php

declare(strict_types=1);

/** Manage DNS claims under tenant locks and activate exclusive host leases only after fresh proof. */

namespace App\Actions\StatusPages;

use App\Data\StatusPages\StatusDomainClaim;
use App\Exceptions\StatusPageRejected;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use App\Services\StatusPages\StatusDomainNames;
use App\Services\StatusPages\StatusDomainProof;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Team → Page locks serialize edits; the registry's hostname primary key serializes cross-team activation. */
final readonly class ManageStatusDomains
{
    /**
     * @param TenantContext $tenants Explicit scope for every page access and write.
     * @param StatusDomainNames $names Network-free hostname normalization and reserved-name rules.
     * @param StatusDomainProof $proof Bounded TXT lookup outside domain transactions.
     */
    public function __construct(private TenantContext $tenants, private StatusDomainNames $names, private StatusDomainProof $proof)
    {
    }

    /**
     * @param User $actor Freshly authorized owner/admin.
     * @param int $teamId Active route tenant.
     * @param int $pageId Page of that tenant.
     * @param string|null $hostname Bare DNS name; null removes the claim and active association.
     * @throws StatusPageRejected When hostname syntax or platform-name policy refuses the claim.
     */
    public function save(User $actor, int $teamId, int $pageId, ?string $hostname): void
    {
        $this->locked($actor, $teamId, $pageId, function (StatusPage $page) use ($hostname): void {
            try {
                $name = $hostname === null ? null : $this->names->normalize($hostname);
            } catch (InvalidArgumentException) {
                throw new StatusPageRejected('Use a public DNS hostname without a scheme, path, port or wildcard.');
            }

            if ($name === $page->custom_domain) {
                return;
            }

            $this->release($page);
            $page->forceFill([
                'custom_domain' => $name,
                'domain_challenge' => $name === null ? null : (string) Str::uuid(),
                'domain_checked_at' => null,
            ])->save();
        });
    }

    /**
     * Reserve the generation under lock, query DNS after commit, then reauthorize and fence that generation.
     * A negative proof revokes only this page's association; it cannot remove another team's active lease.
     *
     * @param User|null $actor Managing user, or null for the explicit system revalidation command.
     * @param int $teamId Discovered or route tenant.
     * @param int $pageId Page of that tenant.
     * @return bool Whether this exact claim now owns an active hostname lease.
     */
    public function verify(?User $actor, int $teamId, int $pageId): bool
    {
        $claim = $this->locked($actor, $teamId, $pageId, static function (StatusPage $page): ?StatusDomainClaim {
            $hostname = $page->custom_domain;
            $challenge = $page->domain_challenge;
            if ($hostname === null || $challenge === null) {
                return null;
            }

            $page->forceFill(['domain_checked_at' => CarbonImmutable::now('UTC')])->save();

            return new StatusDomainClaim($page->team_id, $page->id, $hostname, $challenge);
        });

        if ($claim === null) {
            return false;
        }

        $matches = $this->proof->matches($claim->hostname, $claim->challenge);

        return $this->locked($actor, $teamId, $pageId, function (StatusPage $page) use ($claim, $matches): bool {
            if ($page->custom_domain !== $claim->hostname || $page->domain_challenge !== $claim->challenge) {
                return false;
            }

            if (!$matches) {
                $this->release($page);

                return false;
            }

            $now = CarbonImmutable::now('UTC');
            $expires = $now->addSeconds((int) config('status-domains.lease_seconds'));

            // The conditional upsert is the global ownership boundary: pending claims never reserve a name.
            return DB::affectingStatement(<<<'SQL'
                INSERT INTO status_domain_bindings (hostname, team_id, status_page_id, challenge, verified_at, expires_at)
                VALUES (?, ?, ?, ?, ?, ?)
                ON CONFLICT (hostname) DO UPDATE SET
                    team_id = EXCLUDED.team_id, status_page_id = EXCLUDED.status_page_id,
                    challenge = EXCLUDED.challenge, verified_at = EXCLUDED.verified_at, expires_at = EXCLUDED.expires_at
                WHERE status_domain_bindings.expires_at <= EXCLUDED.verified_at
                    OR (status_domain_bindings.team_id = EXCLUDED.team_id
                        AND status_domain_bindings.status_page_id = EXCLUDED.status_page_id
                        AND status_domain_bindings.challenge = EXCLUDED.challenge)
                SQL, [$claim->hostname, $claim->teamId, $claim->pageId, $claim->challenge, $now, $expires]) === 1;
        });
    }

    /**
     * @param StatusPage $page Locked page whose own active registry row is removed.
     */
    private function release(StatusPage $page): void
    {
        DB::table('status_domain_bindings')->where('team_id', $page->team_id)->where('status_page_id', $page->id)->delete();
    }

    /**
     * @template TResult
     * @param User|null $actor Managing user, rechecked under locks; null for system revalidation only.
     * @param int $teamId Explicit owner.
     * @param int $pageId Page of that owner, foreign pages return ModelNotFoundException.
     * @param Closure(StatusPage): TResult $operation Domain operation under Team → Page locks.
     * @return TResult Operation result; preceding tenant context is restored after success or failure.
     */
    private function locked(?User $actor, int $teamId, int $pageId, Closure $operation): mixed
    {
        return $this->tenants->run($teamId, fn (): mixed => DB::transaction(static function () use ($actor, $teamId, $pageId, $operation): mixed {
            Team::query()->lockForUpdate()->findOrFail($teamId);
            $page = StatusPage::query()->lockForUpdate()->findOrFail($pageId);

            if ($actor !== null) {
                Gate::forUser($actor)->authorize('update', $page);
            }

            return $operation($page);
        }));
    }
}
