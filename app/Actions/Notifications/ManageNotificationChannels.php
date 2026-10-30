<?php

declare(strict_types=1);

/** Create, change, verify, activate and delete notification channels under fresh policy and Team locks. */

namespace App\Actions\Notifications;

use App\Contracts\Notifications\ChannelVerificationSender;
use App\Data\Notifications\QuietHours;
use App\Data\Notifications\WebhookChannelConfiguration;
use App\Enums\Notifications\ChannelType;
use App\Exceptions\ChannelConfigurationInvalid;
use App\Exceptions\ChannelDeliveryFailed;
use App\Exceptions\NotificationChannelRejected;
use App\Models\EscalationPolicy;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Models\User;
use App\Services\Notifications\ChannelConfigurationParser;
use App\Services\Notifications\ChannelVerificationCodes;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Verification lifecycle (user decision): a channel is created inactive; a six-digit code is sent through the
 * channel itself and confirming it verifies and activates the channel. Changing the destination or credentials
 * discards the verification, so a channel is never active towards an unproved destination. Every change locks
 * Team → channel and re-runs NotificationChannelPolicy, so a demoted admin cannot finish a change in flight.
 */
final readonly class ManageNotificationChannels
{
    /** @var int Longest channel label in characters, matching the database check. */
    public const int NAME_MAX_LENGTH = 100;

    /**
     * @param TenantContext $context Explicit tenant boundary for each change.
     * @param ChannelConfigurationParser $parser Shared configuration rules.
     * @param ChannelVerificationCodes $codes Code generation and keyed digests.
     * @param ChannelVerificationSender $sender Delivery of verification messages.
     * @param Repository $config Operational bounds in `notifications.*`.
     */
    public function __construct(
        private TenantContext $context,
        private ChannelConfigurationParser $parser,
        private ChannelVerificationCodes $codes,
        private ChannelVerificationSender $sender,
        private Repository $config,
    ) {
    }

    /**
     * @param User $actor Owner or admin creating the channel.
     * @param int $teamId Active team.
     * @param ChannelType $type Channel family.
     * @param string $name Label; surrounding whitespace is removed.
     * @param array<array-key, mixed> $input Type-specific configuration fields.
     * @return NotificationChannel Inactive, unverified channel.
     * @throws ChannelConfigurationInvalid When the configuration is malformed.
     * @throws NotificationChannelRejected When the label is invalid or the team reached its channel limit.
     */
    public function create(User $actor, int $teamId, ChannelType $type, string $name, array $input): NotificationChannel
    {
        $label = $this->name($name);
        $configuration = $this->parser->parse($type, $input);

        return $this->context->run($teamId, fn (): NotificationChannel => DB::transaction(function () use ($actor, $teamId, $label, $configuration): NotificationChannel {
            Team::query()->lockForUpdate()->findOrFail($teamId);
            Gate::forUser($actor)->authorize('create', NotificationChannel::class);
            $limit = (int) $this->config->get('notifications.channels_per_team');

            if (NotificationChannel::query()->count() >= $limit) {
                throw new NotificationChannelRejected('A team can configure at most '.$limit.' notification channels.');
            }

            $channel = new NotificationChannel();
            $channel->forceFill(['name' => $label, 'created_by' => $actor->id])->assignConfiguration($configuration)->save();

            return $channel;
        }));
    }

    /**
     * Blank secret fields keep the stored secrets. A different destination or credential resets verification
     * and deactivates the channel; a label-only change keeps both.
     *
     * @param User $actor Owner or admin changing the channel.
     * @param int $teamId Active team.
     * @param int $channelId Channel of that team.
     * @param string $name Replacement label.
     * @param array<array-key, mixed> $input Replacement configuration fields.
     * @return NotificationChannel Updated channel.
     * @throws ChannelConfigurationInvalid When the configuration is malformed.
     * @throws NotificationChannelRejected When the label is invalid.
     */
    public function update(User $actor, int $teamId, int $channelId, string $name, array $input): NotificationChannel
    {
        $label = $this->name($name);

        return $this->locked($actor, $teamId, $channelId, function (NotificationChannel $channel) use ($label, $input): NotificationChannel {
            $current = $channel->configuration();
            $replacement = $this->parser->parse($channel->type, $input, $current);
            $channel->forceFill(['name' => $label]);

            if (!$replacement->sameAs($current)) {
                $channel->assignConfiguration($replacement)->forceFill([
                    'is_active' => false,
                    'verified_at' => null,
                    ...$this->clearedCode(),
                    'verification_sent_at' => null,
                ]);
            }

            $channel->save();

            return $channel;
        });
    }

    /**
     * Past delivery logs keep their rows with the channel cleared by the foreign key.
     *
     * @param User $actor Owner or admin deleting the channel.
     * @param int $teamId Active team.
     * @param int $channelId Channel of that team.
     * @throws NotificationChannelRejected When an escalation policy still notifies the channel.
     */
    public function delete(User $actor, int $teamId, int $channelId): void
    {
        $this->locked($actor, $teamId, $channelId, function (NotificationChannel $channel): void {
            $users = EscalationPolicy::query()
                ->orderBy('name')
                ->get()
                ->filter(static fn (EscalationPolicy $policy): bool => in_array($channel->id, $policy->channelIds(), true))
                ->pluck('name')
                ->all();

            if ($users !== []) {
                throw new NotificationChannelRejected('Remove the channel from these escalation policies first: '.implode(', ', $users).'.');
            }

            $channel->delete();
        });
    }

    /**
     * The digest is committed before the message is sent outside the transaction, so a slow provider holds no
     * lock. A failed delivery withdraws that code and frees the resend cooldown.
     *
     * @param User $actor Owner or admin requesting the code.
     * @param int $teamId Active team.
     * @param int $channelId Channel of that team.
     * @throws NotificationChannelRejected When a code was sent within the cooldown or the delivery failed.
     */
    public function sendVerification(User $actor, int $teamId, int $channelId): void
    {
        [$name, $configuration, $code, $digest] = $this->locked($actor, $teamId, $channelId, function (NotificationChannel $channel, CarbonImmutable $now): array {
            $cooldown = (int) $this->config->get('notifications.verification_resend_seconds');
            $sentAt = $channel->verification_sent_at;

            if ($sentAt !== null && $sentAt->addSeconds($cooldown)->isAfter($now)) {
                $wait = (int) ceil($now->diffInSeconds($sentAt->addSeconds($cooldown)));

                throw new NotificationChannelRejected('A verification code was sent recently. Try again in '.$wait.' seconds.');
            }

            $code = $this->codes->generate();
            $digest = $this->codes->digest($channel->id, $code);
            $channel->forceFill([
                'verification_code_hash' => $digest,
                'verification_expires_at' => $now->addSeconds((int) $this->config->get('notifications.verification_ttl_seconds')),
                'verification_attempts' => 0,
                'verification_sent_at' => $now,
            ])->save();

            return [$channel->name, $channel->configuration(), $code, $digest];
        });

        try {
            $this->sender->send($name, $configuration, $code);
        } catch (ChannelDeliveryFailed $exception) {
            $this->withdraw($teamId, $channelId, $digest);

            throw new NotificationChannelRejected('The verification message could not be sent: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * A wrong code is counted and committed before the refusal; the last allowed mistake discards the code.
     *
     * @param User $actor Owner or admin confirming the code.
     * @param int $teamId Active team.
     * @param int $channelId Channel of that team.
     * @param string $code Submitted code.
     * @return NotificationChannel Verified and active channel.
     * @throws NotificationChannelRejected When no code is pending, it expired, or the code is wrong.
     */
    public function confirmVerification(User $actor, int $teamId, int $channelId, string $code): NotificationChannel
    {
        $outcome = $this->locked($actor, $teamId, $channelId, function (NotificationChannel $channel, CarbonImmutable $now) use ($code): NotificationChannel|string {
            if (!$channel->hasPendingCode($now)) {
                return 'No valid code is pending. Request a new verification code.';
            }

            if (hash_equals((string) $channel->verification_code_hash, $this->codes->digest($channel->id, trim($code)))) {
                $channel->forceFill(['is_active' => true, 'verified_at' => $now, ...$this->clearedCode()])->save();

                return $channel;
            }

            $attempts = $channel->verification_attempts + 1;
            $exhausted = $attempts >= (int) $this->config->get('notifications.verification_max_attempts');
            $channel->forceFill($exhausted ? $this->clearedCode() : ['verification_attempts' => $attempts])->save();

            return $exhausted ? 'Too many wrong codes. Request a new verification code.' : 'The code is not correct.';
        });

        if (is_string($outcome)) {
            throw new NotificationChannelRejected($outcome);
        }

        return $outcome;
    }

    /**
     * @param User $actor Owner or admin toggling deliveries.
     * @param int $teamId Active team.
     * @param int $channelId Channel of that team.
     * @param bool $active Whether deliveries should use the channel.
     * @return NotificationChannel Updated channel.
     * @throws NotificationChannelRejected When activating an unverified channel.
     */
    public function setActive(User $actor, int $teamId, int $channelId, bool $active): NotificationChannel
    {
        return $this->locked($actor, $teamId, $channelId, function (NotificationChannel $channel) use ($active): NotificationChannel {
            if ($active && $channel->verified_at === null) {
                throw new NotificationChannelRejected('Verify the channel before activating it.');
            }

            $channel->forceFill(['is_active' => $active])->save();

            return $channel;
        });
    }

    /**
     * @param User $actor Owner or admin changing the channel's current delivery admission rule.
     * @param int $teamId Active team.
     * @param int $channelId Channel of that team.
     * @param QuietHours|null $hours Validated daily interval, or null to disable silencing.
     * @return NotificationChannel Updated channel; destination, verification, pending codes and activation are preserved.
     */
    public function setQuietHours(User $actor, int $teamId, int $channelId, ?QuietHours $hours): NotificationChannel
    {
        return $this->locked($actor, $teamId, $channelId, function (NotificationChannel $channel) use ($hours): NotificationChannel {
            $channel->forceFill([
                'quiet_hours_start' => $hours?->start,
                'quiet_hours_end' => $hours?->end,
                'quiet_hours_timezone' => $hours?->timezone,
            ])->save();

            return $channel;
        });
    }

    /**
     * The destination is unchanged, so verification and activation are kept; the previous key stops working.
     *
     * @param User $actor Owner or admin rotating the key.
     * @param int $teamId Active team.
     * @param int $channelId Webhook channel of that team.
     * @return string New plaintext key, returned once and never stored outside the encrypted configuration.
     * @throws NotificationChannelRejected When the channel is not a webhook.
     */
    public function rotateSigningSecret(User $actor, int $teamId, int $channelId): string
    {
        return $this->locked($actor, $teamId, $channelId, function (NotificationChannel $channel): string {
            $configuration = $channel->configuration();

            if (!$configuration instanceof WebhookChannelConfiguration) {
                throw new NotificationChannelRejected('Only webhook channels have a signing secret.');
            }

            $secret = $this->parser->newSigningSecret();
            $channel->assignConfiguration($configuration->withSigningSecret($secret))->save();

            return $secret;
        });
    }

    /**
     * @param int $teamId Active team.
     * @param int $channelId Channel whose undelivered code is withdrawn.
     * @param string $digest Digest of that code; a newer code is left untouched.
     */
    private function withdraw(int $teamId, int $channelId, string $digest): void
    {
        $this->context->run($teamId, static fn (): int => NotificationChannel::query()
            ->whereKey($channelId)
            ->where('verification_code_hash', $digest)
            ->update([
                'verification_code_hash' => null,
                'verification_expires_at' => null,
                'verification_attempts' => 0,
                'verification_sent_at' => null,
            ]));
    }

    /** @return array{verification_code_hash: null, verification_expires_at: null, verification_attempts: int} Attributes discarding a pending code. */
    private function clearedCode(): array
    {
        return ['verification_code_hash' => null, 'verification_expires_at' => null, 'verification_attempts' => 0];
    }

    /**
     * @param string $value Submitted label.
     * @return string Trimmed nonblank label within the limit.
     * @throws NotificationChannelRejected When the label is blank or too long.
     */
    private function name(string $value): string
    {
        $text = trim($value);

        if ($text === '' || mb_strlen($text) > self::NAME_MAX_LENGTH) {
            throw new NotificationChannelRejected('A channel name must be between 1 and '.self::NAME_MAX_LENGTH.' characters.');
        }

        return $text;
    }

    /**
     * A missing or foreign channel raises ModelNotFoundException; an insufficient role AuthorizationException.
     *
     * @template TResult
     * @param User $actor Manager re-authorized under the locks.
     * @param int $teamId Active team.
     * @param int $channelId Channel of that team.
     * @param Closure(NotificationChannel, CarbonImmutable): TResult $operation Change applied to the locked channel.
     * @return TResult Operation result.
     */
    private function locked(User $actor, int $teamId, int $channelId, Closure $operation): mixed
    {
        return $this->context->run($teamId, fn (): mixed => DB::transaction(function () use ($actor, $teamId, $channelId, $operation): mixed {
            Team::query()->lockForUpdate()->findOrFail($teamId);
            $channel = NotificationChannel::query()->lockForUpdate()->findOrFail($channelId);
            Gate::forUser($actor)->authorize('update', $channel);

            return $operation($channel, CarbonImmutable::now('UTC'));
        }));
    }
}
