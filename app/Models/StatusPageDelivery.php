<?php

declare(strict_types=1);

/** Persist encrypted email content with a bounded attempt budget and worker ownership fence. */

namespace App\Models;

use App\Enums\Notifications\DeliveryStatus;
use App\Models\Concerns\BelongsToTeam;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id Outbox identity.
 * @property int $team_id Immutable owner.
 * @property int $status_page_id Owning page.
 * @property int $subscriber_id Intended recipient.
 * @property int|null $incident_update_id Explicitly published entry; null for verification.
 * @property string $generation Consent generation frozen at preparation.
 * @property string $message_uuid Stable message identity across retries.
 * @property string $kind Verification or public update.
 * @property array{subject: string, text: string} $payload Encrypted recipient content.
 * @property DeliveryStatus $status Persistent delivery state.
 * @property int $attempts Acquired provider attempts, including expired workers.
 * @property CarbonImmutable $available_at Earliest acquisition time in UTC.
 * @property string|null $claim_token Worker ownership fence.
 * @property CarbonImmutable|null $claim_until Lease expiry in UTC.
 * @property CarbonImmutable|null $sent_at SMTP acceptance time, not inbox delivery.
 */
final class StatusPageDelivery extends Model
{
    /** Require explicit tenant context for private reads and writes. */
    use BelongsToTeam;

    /** @var list<string> Only outbox services write deliveries. */
    protected $guarded = ['*'];

    /** @var list<string> Message text, capabilities and fences never reach public serialization. */
    protected $hidden = ['payload', 'generation', 'claim_token'];

    /** @return array<string, string> Encrypted message, typed status, counters and UTC instants. */
    protected function casts(): array
    {
        return [
            'team_id' => 'integer',
            'status_page_id' => 'integer',
            'subscriber_id' => 'integer',
            'incident_update_id' => 'integer',
            'payload' => 'encrypted:array',
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'claim_until' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param mixed $value Date accepted by Eloquent, including null.
     * @return string|null UTC timestamp independent of the application's timezone.
     */
    public function fromDateTime($value): ?string
    {
        return $value === null || $value === '' ? $value : $this->asDateTime($value)->utc()->format($this->getDateFormat());
    }
}
