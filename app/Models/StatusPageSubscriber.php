<?php

declare(strict_types=1);

/** Store one encrypted email address and its revocable consent for one team's status page. */

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id Recipient identity.
 * @property int $team_id Immutable owner.
 * @property int $status_page_id Page receiving consent.
 * @property string $email Decrypted canonical email, never serialized.
 * @property string $email_hash Keyed uniqueness digest.
 * @property string $generation Rotatable capability and consent generation.
 * @property string $access_hash Password fingerprint at consent.
 * @property CarbonImmutable $requested_at Last verification request in UTC.
 * @property CarbonImmutable $verification_expires_at Verification deadline, exclusive.
 * @property CarbonImmutable|null $verified_at Mailbox confirmation time.
 * @property CarbonImmutable|null $unsubscribed_at Revocation time.
 */
final class StatusPageSubscriber extends Model
{
    /** Require explicit tenant context for reads and writes. */
    use BelongsToTeam;

    /** @var list<string> Consent is written only by the subscription service. */
    protected $guarded = ['*'];

    /** @var list<string> Recipient data and capabilities never belong in a projection. */
    protected $hidden = ['email', 'email_hash', 'generation', 'access_hash'];

    /** @return array<string, string> Encrypted mailbox and immutable UTC lifecycle values. */
    protected function casts(): array
    {
        return [
            'team_id' => 'integer',
            'status_page_id' => 'integer',
            'email' => 'encrypted',
            'requested_at' => 'immutable_datetime',
            'verification_expires_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'unsubscribed_at' => 'immutable_datetime',
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
