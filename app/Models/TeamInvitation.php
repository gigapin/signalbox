<?php

/** Store invitation capabilities as hashes with an exclusive UTC expiry. */

namespace App\Models;

use App\Enums\Identity\TeamRole;
use Illuminate\Database\Eloquent\Model;

/**
 * Persist one lifecycle per team/email; trusted actions alone write these attributes.
 * @property int $id Invitation identifier, not a bearer credential.
 * @property int $team_id Team issuing the invitation.
 * @property int|null $invited_by Original or most recent sender, nullable after user deletion.
 * @property string $email Normalized intended account email.
 * @property TeamRole $role Role granted only on first acceptance.
 * @property string $token_hash SHA-256 digest; raw tokens exist only during delivery.
 * @property \Carbon\CarbonImmutable $expires_at Exclusive invitation validity boundary.
 * @property \Carbon\CarbonImmutable|null $accepted_at Successful first acceptance instant.
 * @property \Carbon\CarbonImmutable|null $revoked_at Explicit revocation instant.
 */
final class TeamInvitation extends Model
{
    /** @var list<string> $guarded All invitation fields require explicit trusted assignment. */
    protected $guarded = ['*'];

    /** @var list<string> $hidden Never serialize the capability digest. */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> Typed roles and immutable UTC lifecycle timestamps. */
    protected function casts(): array
    {
        return [
            'role' => TeamRole::class,
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime'
        ];
    }

    /** @return bool Whether a first acceptance is still permitted at the current application clock. */
    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
