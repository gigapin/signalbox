<?php

declare(strict_types=1);

/** Revalidate old DNS claims in bounded batches so revoked proof cannot leave a permanent hostname association. */

namespace App\Console\Commands;

use App\Actions\StatusPages\ManageStatusDomains;
use App\Models\Scopes\TeamScope;
use App\Models\StatusPage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Only scalar identities cross global discovery; all verification phases establish their explicit owning tenant. */
final class VerifyStatusDomains extends Command
{
    /** @var string Bounded operational entrypoint, including unverified claims. */
    protected $signature = 'status-pages:verify-domains {--limit=25 : Maximum claims per pass, between 1 and 100}';

    /** @var string Purpose shown by Artisan help. */
    protected $description = 'Recheck due status page domain ownership and expire unavailable DNS proof';

    /**
     * @param ManageStatusDomains $domains Shared generation-fenced verifier.
     * @return int Failure after isolated infrastructure errors; an ordinary missing proof is not an infrastructure error.
     */
    public function handle(ManageStatusDomains $domains): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) {
            $this->error('The limit must be between 1 and 100.');

            return self::INVALID;
        }

        $due = CarbonImmutable::now('UTC')->subSeconds((int) config('status-domains.recheck_seconds'));
        $rows = StatusPage::withoutGlobalScope(TeamScope::class)->select(['id', 'team_id'])
            ->whereNotNull('custom_domain')
            ->where(fn (Builder $query): Builder => $query->whereNull('domain_checked_at')->orWhere('domain_checked_at', '<=', $due))
            ->orderByRaw('domain_checked_at ASC NULLS FIRST')->orderBy('id')->limit($limit)->get();
        $failed = false;

        foreach ($rows as $row) {
            try {
                $domains->verify(null, $row->team_id, $row->id);
            } catch (Throwable) {
                $failed = true;
                Log::warning('Status domain verification failed.', ['team_id' => $row->team_id, 'page_id' => $row->id]);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
