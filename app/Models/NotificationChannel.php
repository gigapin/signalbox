<?php

declare(strict_types=1);

/** Persist one team notification destination with its encrypted configuration and verification state. */

namespace App\Models;

use App\Contracts\Notifications\ChannelConfiguration;
use App\Data\Notifications\QuietHours;
use App\Enums\Notifications\ChannelType;
use App\Models\Concerns\BelongsToTeam;
use App\Services\Notifications\ChannelConfigurationParser;
use Carbon\CarbonImmutable;
use Database\Factories\NotificationChannelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Written by ManageNotificationChannels. The configuration and the code digest are hidden from serialization;
 * pages use NotificationChannelPresenter, which exposes only masked hints.
 *
 * @property int $id Channel identity.
 * @property int $team_id Immutable owning tenant.
 * @property ChannelType $type Immutable channel family.
 * @property string $name Nonblank label of at most 100 characters.
 * @property string|null $quiet_hours_start Inclusive daily local HH:mm, null when silencing is disabled.
 * @property string|null $quiet_hours_end Exclusive daily local HH:mm, distinct from start; null when disabled.
 * @property string|null $quiet_hours_timezone IANA timezone of the daily interval; null when disabled.
 * @property array<string, string> $config_json Decrypted configuration, secrets included.
 * @property bool $is_active Whether deliveries use the channel; requires a verification.
 * @property CarbonImmutable|null $verified_at UTC instant the current destination was verified; reset when it changes.
 * @property string|null $verification_code_hash HMAC of the pending code.
 * @property CarbonImmutable|null $verification_expires_at UTC expiry of the pending code.
 * @property int $verification_attempts Wrong codes submitted for the pending code.
 * @property CarbonImmutable|null $verification_sent_at UTC instant of the last delivered verification message.
 * @property int|null $created_by Creating user; null when deleted.
 * @property CarbonImmutable|null $created_at UTC creation timestamp.
 * @property CarbonImmutable|null $updated_at UTC modification timestamp.
 */
final class NotificationChannel extends Model
{
    /** Apply the fail-closed tenant query and write boundary. */
    use BelongsToTeam;

    /** @use HasFactory<NotificationChannelFactory> Synthetic channels for tests. */
    use HasFactory;

    /** @var list<string> Channels are written explicitly by the owning action. */
    protected $guarded = ['*'];

    /** @var list<string> Secrets and code digests never leave through Eloquent serialization. */
    protected $hidden = ['config_json', 'verification_code_hash'];

    /** @var array<string, mixed> New channels start inactive and unverified. */
    protected $attributes = [
        'is_active' => false,
        'verification_attempts' => 0,
    ];

    /** @return array<string, string> Enum type, encrypted configuration and immutable UTC instants. */
    protected function casts(): array
    {
        return [
            'team_id' => 'integer',
            'type' => ChannelType::class,
            'config_json' => 'encrypted:array',
            'is_active' => 'boolean',
            'verified_at' => 'immutable_datetime',
            'verification_expires_at' => 'immutable_datetime',
            'verification_attempts' => 'integer',
            'verification_sent_at' => 'immutable_datetime',
            'created_by' => 'integer',
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

    /** @return ChannelConfiguration Typed view of the decrypted configuration. */
    public function configuration(): ChannelConfiguration
    {
        return app(ChannelConfigurationParser::class)->hydrate($this->type, $this->config_json);
    }

    /**
     * @return QuietHours|null Current daily silencing rule, independently of the provider destination.
     * @throws LogicException When stored configuration is incomplete.
     * @throws \InvalidArgumentException When stored times or timezone are invalid.
     */
    public function quietHours(): ?QuietHours
    {
        if ($this->quiet_hours_start === null && $this->quiet_hours_end === null && $this->quiet_hours_timezone === null) {
            return null;
        }

        if ($this->quiet_hours_start === null || $this->quiet_hours_end === null || $this->quiet_hours_timezone === null) {
            throw new LogicException('Stored quiet hours are incomplete.');
        }

        return new QuietHours($this->quiet_hours_start, $this->quiet_hours_end, $this->quiet_hours_timezone);
    }

    /**
     * @param ChannelConfiguration $configuration Validated replacement of the same type.
     * @return $this Channel with the configuration assigned but not saved.
     */
    public function assignConfiguration(ChannelConfiguration $configuration): static
    {
        $this->type = $configuration->type();
        $this->config_json = $configuration->toArray();

        return $this;
    }

    /**
     * @param CarbonImmutable $now Current UTC instant.
     * @return bool Whether an unexpired code is waiting to be confirmed.
     */
    public function hasPendingCode(CarbonImmutable $now): bool
    {
        return $this->verification_code_hash !== null
            && $this->verification_expires_at !== null
            && $this->verification_expires_at->isAfter($now);
    }
}
