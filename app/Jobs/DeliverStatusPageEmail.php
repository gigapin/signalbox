<?php

declare(strict_types=1);

/** Execute one scalar public email intent; the durable ledger owns retries and consent admission. */

namespace App\Jobs;

use App\Contracts\Tenancy\TenantJob;
use App\Jobs\Middleware\UseTenantContext;
use App\Mail\StatusPageMail;
use App\Services\Notifications\NotificationDeliverySettings;
use App\Services\StatusPages\StatusEmailLedger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** No mailbox, capability link or message text is serialized into Redis or failed_jobs. */
final class DeliverStatusPageEmail implements ShouldQueue, TenantJob
{
    /** @var int One broker attempt; explicit persisted retries belong to email delivery. */
    public int $tries = 1;

    /** @var int Seconds below the 75-second lease and Redis's 90-second reservation. */
    public int $timeout = NotificationDeliverySettings::WORKER_TIMEOUT;

    /** @var bool Let failed() record a safe timeout result. */
    public bool $failOnTimeout = true;

    /**
     * @param int $teamId Committed owner.
     * @param int $deliveryId Durable outbox identity.
     * @param string $token Unique queue envelope fence.
     */
    public function __construct(public readonly int $teamId, public readonly int $deliveryId, public readonly string $token)
    {
    }

    /** @return int Explicit tenant, established before any private lookup. */
    public function tenantId(): int
    {
        return $this->teamId;
    }

    /** @return list<UseTenantContext> Restore worker context even after failures. */
    public function middleware(): array
    {
        return [new UseTenantContext()];
    }

    /** @param StatusEmailLedger $ledger Persistent admission, fencing and retry policy. */
    public function handle(StatusEmailLedger $ledger): void
    {
        $attempt = $ledger->acquire($this->teamId, $this->deliveryId, $this->token);

        if ($attempt === null) {
            return;
        }

        try {
            // Explicit SMTP prevents MAIL_MAILER=log from recording mailbox capabilities or public message text.
            Mail::mailer('smtp')->to($attempt->email)->send(new StatusPageMail($attempt));
        } catch (Throwable) {
            $ledger->finish($this->teamId, $this->deliveryId, $this->token, false);

            return;
        }

        $ledger->finish($this->teamId, $this->deliveryId, $this->token, true);
    }

    /** @param Throwable|null $exception Deliberately ignore raw provider/worker exception data. */
    public function failed(#[\SensitiveParameter] ?Throwable $exception): void
    {
        app(StatusEmailLedger::class)->finish($this->teamId, $this->deliveryId, $this->token, false);
    }
}
