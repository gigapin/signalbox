<?php

declare(strict_types=1);

/** Recover due public email intents independently of HTTP and incident process lifetimes. */

namespace App\Console\Commands;

use App\Jobs\DeliverStatusPageEmail;
use App\Models\Scopes\TeamScope;
use App\Models\StatusPageDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** This global scan reads only identities and eligibility, never mailbox or encrypted content. */
final class DispatchStatusEmails extends Command
{
    /** @var string Stable outbox recovery entrypoint. */
    protected $signature = 'status-pages:dispatch-emails';

    /** @var string Purpose shown by Artisan help. */
    protected $description = 'Publish due status page verification and public update emails';

    /**
     * @param Factory $queues Explicit Redis publisher, even when the default connection is synchronous.
     * @return int Failure if any publication was unconfirmed; other tenants still proceed.
     */
    public function handle(Factory $queues): int
    {
        $failed = false;
        $now = CarbonImmutable::now('UTC');

        StatusPageDelivery::withoutGlobalScope(TeamScope::class)->select(['id', 'team_id'])
            ->where('status', 'pending')->where('available_at', '<=', $now)
            ->where(fn (Builder $query): Builder => $query->whereNull('claim_until')->orWhere('claim_until', '<=', $now))
            ->chunkById(100, function ($rows) use ($queues, &$failed): void {
                foreach ($rows as $row) {
                    try {
                        $queues->connection('redis')->push(new DeliverStatusPageEmail($row->team_id, $row->id, (string) Str::uuid()), '', 'notifications');
                    } catch (Throwable) {
                        $failed = true;
                        Log::warning('Status page email publication unconfirmed.', ['team_id' => $row->team_id, 'delivery_id' => $row->id]);
                    }
                }
            });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
