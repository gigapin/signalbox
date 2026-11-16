<?php

declare(strict_types=1);

/** Rewrap stored ciphertext and authenticated digests without rotating configuration or renewing consent. */

namespace App\Services\Security;

use App\Enums\Security\KeyPurpose;
use App\Models\NotificationChannel;
use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use App\Services\Notifications\ChannelDestinationFingerprint;
use App\Services\StatusPages\StatusSubscriptionLinks;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Operator-only global scan; every mutation locks its row, and domain digest transitions use parent locks. */
final readonly class RotateProtectedData
{
    /** @var array<string, list<string>> Complete application-owned native encrypted storage registry. */
    private const array ENCRYPTED = [
        'users' => ['two_factor_secret', 'two_factor_recovery_codes'],
        'monitors' => ['url', 'headers_json', 'body'],
        'notification_channels' => ['config_json'],
        'notification_logs' => ['message_payload'],
        'status_page_subscribers' => ['email'],
        'status_page_deliveries' => ['payload'],
    ];

    /**
     * @param PurposeKeys $keys Current/previous derived keys and optional original-format readers.
     * @param TenantContext $tenants Explicit ownership for domain models reached by the global operator scan.
     * @param StatusSubscriptionLinks $links Password-bound consent and historical unsubscribe capabilities.
     * @param ChannelDestinationFingerprint $destinations Authenticated frozen destination equivalence.
     */
    public function __construct(
        private PurposeKeys $keys,
        private TenantContext $tenants,
        private StatusSubscriptionLinks $links,
        private ChannelDestinationFingerprint $destinations,
    ) {
    }

    /**
     * @param bool $check Count required changes without writing, even when historical keys are configured.
     * @param int $batch Maximum identities loaded per scan chunk, from 1 through 1000.
     * @return array{changes: int, pending_codes: int} Changes made/needed and live codes awaiting historical keys.
     * @throws LogicException Corrupt/unreadable data prevents a safe migration; no plaintext diagnostics are kept.
     */
    public function run(bool $check, int $batch): array
    {
        if ($batch < 1 || $batch > 1000) {
            throw new LogicException('The batch size must be between 1 and 1000.');
        }

        $reader = $this->keys->encrypter();
        $current = new Encrypter($reader->getKey(), (string) config('app.cipher'));
        $changes = 0;
        foreach (self::ENCRYPTED as $table => $fields) {
            DB::table($table)->select('id')->orderBy('id')->chunkById($batch, function ($rows) use ($table, $fields, $reader, $current, $check, &$changes): void {
                foreach ($rows as $identity) {
                    $changes += DB::transaction(fn (): int => $this->rewrap($table, (int) $identity->id, $fields, $reader, $current, $check));
                }
            });
        }

        DB::table('status_page_subscribers')->select(['id', 'team_id', 'status_page_id'])->orderBy('id')
            ->chunkById($batch, function ($rows) use ($check, &$changes): void {
                foreach ($rows as $row) {
                    $changes += $this->subscriber((int) $row->team_id, (int) $row->status_page_id, (int) $row->id, $check);
                }
            });
        DB::table('notification_logs')->whereNotNull('destination_hash')->select(['id', 'team_id', 'channel_id'])->orderBy('id')
            ->chunkById($batch, function ($rows) use ($check, &$changes): void {
                foreach ($rows as $row) {
                    $changes += $this->destination((int) $row->team_id, (int) $row->id, $row->channel_id === null ? null : (int) $row->channel_id, $check);
                }
            });

        if (!$check) {
            DB::table('notification_channels')->whereNotNull('verification_code_hash')
                ->where('verification_expires_at', '<=', now())->update([
                    'verification_code_hash' => null, 'verification_expires_at' => null, 'verification_attempts' => 0,
                ]);
        }
        $prefix = substr($this->keys->digest(KeyPurpose::ChannelVerification, ''), 0, 21);
        $pending = DB::table('notification_channels')->whereNotNull('verification_code_hash')
            ->where('verification_expires_at', '>', now())->where('verification_code_hash', 'not like', $prefix.'%')->count();

        return ['changes' => $changes, 'pending_codes' => $pending];
    }

    /**
     * @param string $table Registry-selected table; never supplied by a CLI caller.
     * @param int $id Scanned row identity, reloaded under a lock before rewriting.
     * @param list<string> $fields Registry-selected encrypted columns.
     * @param Encrypter $reader Current and explicitly configured historical keys.
     * @param Encrypter $current Current derived write key only.
     * @param bool $check Whether writes are forbidden.
     * @return int Number of ciphertext fields changed or requiring rewrap.
     */
    private function rewrap(string $table, int $id, array $fields, Encrypter $reader, Encrypter $current, bool $check): int
    {
        $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
        if ($row === null) {
            return 0;
        }
        $updates = [];
        foreach ($fields as $field) {
            $ciphertext = $row->$field;
            if ($ciphertext === null) {
                continue;
            }
            if (!is_string($ciphertext)) {
                throw new LogicException('Protected data could not be read safely.');
            }
            try {
                $current->decrypt($ciphertext, false);
                continue;
            } catch (DecryptException) {
                // Preserve the exact decrypted bytes: Fortify serializes strings; native casts encode JSON.
            }
            try {
                $plaintext = $reader->decrypt($ciphertext, false);
                $updates[$field] = $check ? '' : $current->encrypt($plaintext, false);
            } catch (DecryptException) {
                throw new LogicException('Protected data could not be read safely.');
            }
        }
        if (!$check && $updates !== []) {
            DB::table($table)->where('id', $id)->update($updates);
        }

        return count($updates);
    }

    /**
     * @param int $teamId Explicit owner from the global scan.
     * @param int $pageId Parent whose lock serializes subscriber uniqueness and consent.
     * @param int $id Recipient identity, reloaded after Team and Page.
     * @param bool $check Whether writes are forbidden.
     * @return int Changed/required digests and missing exact unsubscribe grants.
     */
    private function subscriber(int $teamId, int $pageId, int $id, bool $check): int
    {
        return $this->tenants->run($teamId, fn (): int => DB::transaction(function () use ($teamId, $pageId, $id, $check): int {
            DB::table('teams')->where('id', $teamId)->lockForUpdate()->first();
            $page = StatusPage::query()->lockForUpdate()->find($pageId);
            $subscriber = StatusPageSubscriber::query()->where('status_page_id', $pageId)->lockForUpdate()->find($id);
            if ($page === null || $subscriber === null) {
                return 0;
            }

            $address = mb_strtolower(trim($subscriber->email));
            if (!$this->keys->matches($subscriber->email_hash, KeyPurpose::SubscriberEmail, $pageId.':'.$address, $address)) {
                throw new LogicException('A subscriber identity could not be authenticated.');
            }
            $email = $this->keys->digest(KeyPurpose::SubscriberEmail, $pageId.':'.$address);
            if (!$this->keys->knowsDigestKey($subscriber->access_hash)) {
                throw new LogicException('A consent key generation is unavailable.');
            }
            $access = $this->links->allowsConsent($page, $subscriber->access_hash)
                ? $this->links->accessHash($page) : $this->retired($subscriber->access_hash);
            $updates = [];
            if ($email !== $subscriber->email_hash) {
                $updates['email_hash'] = $email;
            }
            if ($access !== $subscriber->access_hash) {
                $updates['access_hash'] = $access;
            }
            if (!$check && $updates !== []) {
                // The page lock prevents new duplicate recipients; a pre-existing conflict aborts rather than merges consent.
                $subscriber->forceFill($updates)->save();
            }
            $changes = count($updates);
            foreach ($this->links->retirementPaths($subscriber) as $path) {
                $hash = hash('sha256', $path);
                if (!DB::table('status_subscription_link_grants')->where('link_hash', $hash)->exists()) {
                    $changes++;
                    if (!$check) {
                        DB::table('status_subscription_link_grants')->insertOrIgnore([
                            'team_id' => $teamId, 'status_page_id' => $pageId, 'subscriber_id' => $id,
                            'generation' => $subscriber->generation, 'link_hash' => $hash,
                        ]);
                    }
                }
            }

            return $changes;
        }));
    }

    /**
     * @param int $teamId Explicit owning tenant.
     * @param int $id Frozen delivery identity.
     * @param int|null $channelId Current channel, or null when it was deleted.
     * @param bool $check Whether writes are forbidden.
     * @return int One when a digest changes or needs conversion; stale destinations remain invalid.
     */
    private function destination(int $teamId, int $id, ?int $channelId, bool $check): int
    {
        return $this->tenants->run($teamId, fn (): int => DB::transaction(function () use ($teamId, $id, $channelId, $check): int {
            DB::table('teams')->where('id', $teamId)->lockForUpdate()->first();
            $log = DB::table('notification_logs')->where('team_id', $teamId)->where('id', $id)->lockForUpdate()->first();
            if ($log === null) {
                return 0;
            }
            $channel = $channelId === null ? null : NotificationChannel::query()->find($channelId);
            $stored = (string) $log->destination_hash;
            if (!$this->keys->knowsDigestKey($stored)) {
                throw new LogicException('A destination key generation is unavailable.');
            }
            $replacement = $this->retired($stored);
            if ($channel !== null && $this->destinations->matches($stored, $channel->configuration())) {
                $replacement = $this->destinations->for($channel->configuration());
            }
            if ($replacement === $stored) {
                return 0;
            }
            if (!$check) {
                DB::table('notification_logs')->where('team_id', $teamId)->where('id', $id)->update(['destination_hash' => $replacement]);
            }

            return 1;
        }));
    }

    /**
     * @param string $stored Obsolete opaque proof, or an already retired marker.
     * @return string Stable, key-independent marker that cannot become a valid new-format domain proof.
     */
    private function retired(string $stored): string
    {
        return str_starts_with($stored, 'retired:') ? $stored : 'retired:'.hash('sha256', $stored);
    }
}
