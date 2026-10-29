<?php

declare(strict_types=1);

/** Persist the outcome of one notification delivery for one incident and channel. */

namespace App\Models;

use App\Enums\Notifications\ChannelType;
use App\Enums\Notifications\DeliveryStatus;
use App\Enums\Notifications\NotificationKind;
use App\Models\Concerns\BelongsToTeam;
use Carbon\CarbonImmutable;
use Database\Factories\NotificationLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Delivery jobs (M5.03) create one row per dedupe key and update it as attempts progress; the incident page
 * reads it. The response is a redacted summary, never a credential or a full payload.
 *
 * @property int $id Log identity.
 * @property int $team_id Immutable owning tenant.
 * @property int $incident_id Notified incident.
 * @property int|null $channel_id Target channel; null once the channel is deleted.
 * @property ChannelType $channel_type Channel family snapshot.
 * @property NotificationKind $kind Incident moment that produced the delivery.
 * @property int|null $step_index Zero-based escalation step, or null outside a policy step.
 * @property DeliveryStatus $status Current delivery state.
 * @property int $attempts Delivery attempts made so far.
 * @property string|null $response Redacted provider outcome of at most 1000 characters.
 * @property string $dedupe_key Idempotency identity unique per team.
 * @property CarbonImmutable|null $sent_at UTC instant the provider accepted the message.
 * @property CarbonImmutable|null $created_at UTC creation timestamp.
 * @property CarbonImmutable|null $updated_at UTC modification timestamp.
 * @property-read NotificationChannel|null $channel Target channel within the same tenant, if it still exists.
 */
final class NotificationLog extends Model
{
    /** Apply the fail-closed tenant query and write boundary. */
    use BelongsToTeam;

    /** @use HasFactory<NotificationLogFactory> Synthetic log rows for tests. */
    use HasFactory;

    /** @var list<string> Rows are written explicitly by delivery code. */
    protected $guarded = ['*'];

    /** @var list<string> The dedupe key is internal bookkeeping. */
    protected $hidden = ['dedupe_key'];

    /** @var array<string, mixed> Rows start pending with no attempt. */
    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    /** @return array<string, string> Enums, integers and immutable UTC instants. */
    protected function casts(): array
    {
        return [
            'team_id' => 'integer',
            'incident_id' => 'integer',
            'channel_id' => 'integer',
            'channel_type' => ChannelType::class,
            'kind' => NotificationKind::class,
            'step_index' => 'integer',
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'sent_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Normalize instants before Laravel formats timestamp values without a timezone offset.
     *
     * @param mixed $value Date value accepted by Eloquent, including null and empty attributes.
     * @return string|null UTC timestamp for PostgreSQL, preserving null and empty values.
     */
    public function fromDateTime($value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return $this->asDateTime($value)->utc()->format($this->getDateFormat());
    }

    /** @return BelongsTo<NotificationChannel, $this> Target channel, resolved through the tenant scope. */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'channel_id');
    }
}
