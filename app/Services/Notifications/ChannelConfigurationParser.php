<?php

declare(strict_types=1);

/** Validate and normalize notification channel input into typed configurations without network access. */

namespace App\Services\Notifications;

use App\Contracts\Notifications\ChannelConfiguration;
use App\Data\Notifications\EmailChannelConfiguration;
use App\Data\Notifications\SlackChannelConfiguration;
use App\Data\Notifications\SmsChannelConfiguration;
use App\Data\Notifications\TelegramChannelConfiguration;
use App\Data\Notifications\WebhookChannelConfiguration;
use App\Enums\Notifications\ChannelType;
use App\Exceptions\ChannelConfigurationInvalid;
use App\Services\Monitoring\MonitorInputSyntax;
use InvalidArgumentException;
use LogicException;

/**
 * One parser serves the browser, the future API and tests, so every entrypoint applies the same rules. When a
 * current configuration is given, a blank secret field keeps the stored secret: forms never receive secrets
 * back. Syntax checks authorize no destination; delivery adapters re-run SSRF checks at access time (ADR-002).
 */
final readonly class ChannelConfigurationParser
{
    /** @var array<string, list<string>> Secret fields per channel type that a blank update keeps. */
    public const array SECRET_FIELDS = [
        'slack' => ['webhook_url'],
        'telegram' => ['bot_token'],
        'webhook' => ['url'],
    ];

    /** @var int Longest accepted email address in bytes (RFC 5321 path limit). */
    private const int EMAIL_MAX_BYTES = 254;

    /** @var string Telegram Bot API token: numeric bot identity, a colon and the secret part. */
    private const string TELEGRAM_TOKEN_PATTERN = '/^\d{5,16}:[A-Za-z0-9_-]{30,64}$/D';

    /** @var string Numeric chat identity (groups are negative) or a public `@name`. */
    private const string TELEGRAM_CHAT_PATTERN = '/^(-?\d{1,20}|@[A-Za-z][A-Za-z0-9_]{4,31})$/D';

    /** @var string E.164 number: plus sign and 8–15 digits not starting with zero. */
    private const string PHONE_PATTERN = '/^\+[1-9]\d{7,14}$/D';

    /** @param MonitorInputSyntax $syntax Shared network-free URL parser. */
    public function __construct(private MonitorInputSyntax $syntax)
    {
    }

    /**
     * @param ChannelType $type Channel family; immutable after creation.
     * @param array<array-key, mixed> $input Submitted fields; unknown keys are ignored.
     * @param ChannelConfiguration|null $current Stored configuration of the channel being edited, or null on creation.
     * @return ChannelConfiguration Normalized configuration.
     * @throws ChannelConfigurationInvalid When any field is missing or malformed.
     */
    public function parse(ChannelType $type, array $input, ?ChannelConfiguration $current = null): ChannelConfiguration
    {
        if ($current !== null && $current->type() !== $type) {
            throw new LogicException('A channel type cannot change.');
        }

        $values = $this->withKeptSecrets($type, $input, $current);
        $errors = [];

        $configuration = match ($type) {
            ChannelType::Email => $this->email($values, $errors),
            ChannelType::Slack => $this->slack($values, $errors),
            ChannelType::Telegram => $this->telegram($values, $errors),
            ChannelType::Webhook => $this->webhook($values, $errors, $current),
            ChannelType::Sms => $this->sms($values, $errors),
        };

        if ($errors !== [] || $configuration === null) {
            throw new ChannelConfigurationInvalid($errors);
        }

        return $configuration;
    }

    /**
     * Rebuild a configuration read from the encrypted column, which only this parser has written.
     *
     * @param ChannelType $type Persisted channel family.
     * @param array<array-key, mixed> $stored Decrypted `config_json` object.
     * @return ChannelConfiguration Typed configuration.
     * @throws LogicException When the stored object does not match its type.
     */
    public function hydrate(ChannelType $type, array $stored): ChannelConfiguration
    {
        $field = static function (string $name) use ($stored): string {
            $value = $stored[$name] ?? null;

            if (!is_string($value) || $value === '') {
                throw new LogicException('A stored channel configuration is incomplete.');
            }

            return $value;
        };

        return match ($type) {
            ChannelType::Email => new EmailChannelConfiguration($field('address')),
            ChannelType::Slack => new SlackChannelConfiguration($field('webhook_url')),
            ChannelType::Telegram => new TelegramChannelConfiguration($field('bot_token'), $field('chat_id')),
            ChannelType::Webhook => new WebhookChannelConfiguration($field('url'), $field('signing_secret')),
            ChannelType::Sms => new SmsChannelConfiguration($field('phone')),
        };
    }

    /** @return string New 256-bit hexadecimal webhook signing key. */
    public function newSigningSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * @param ChannelType $type Channel family.
     * @param array<array-key, mixed> $input Submitted fields.
     * @param ChannelConfiguration|null $current Stored configuration, if editing.
     * @return array<string, string> Trimmed string fields, with blank secrets replaced by the stored ones.
     */
    private function withKeptSecrets(ChannelType $type, array $input, ?ChannelConfiguration $current): array
    {
        $values = [];

        foreach ($input as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $values[$key] = trim($value);
            }
        }

        if ($current === null) {
            return $values;
        }

        $stored = $current->toArray();

        foreach (self::SECRET_FIELDS[$type->value] ?? [] as $field) {
            if (($values[$field] ?? '') === '') {
                $values[$field] = $stored[$field];
            }
        }

        return $values;
    }

    /**
     * @param array<string, string> $values Trimmed fields.
     * @param array<string, string> $errors Collected messages, extended by reference.
     * @return EmailChannelConfiguration|null Configuration, or null when invalid.
     */
    private function email(array $values, array &$errors): ?EmailChannelConfiguration
    {
        $address = strtolower($values['address'] ?? '');

        if ($address === '' || strlen($address) > self::EMAIL_MAX_BYTES || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            $errors['address'] = 'Enter a valid email address.';

            return null;
        }

        return new EmailChannelConfiguration($address);
    }

    /**
     * @param array<string, string> $values Trimmed fields.
     * @param array<string, string> $errors Collected messages, extended by reference.
     * @return SlackChannelConfiguration|null Configuration, or null when invalid.
     */
    private function slack(array $values, array &$errors): ?SlackChannelConfiguration
    {
        $url = $this->normalizedUrl($values['webhook_url'] ?? '');
        $valid = $url !== null
            && str_starts_with($url, 'https://hooks.slack.com/services/')
            && strlen($url) > strlen('https://hooks.slack.com/services/');

        if (!$valid) {
            $errors['webhook_url'] = 'Enter a Slack incoming webhook URL starting with https://hooks.slack.com/services/.';

            return null;
        }

        return new SlackChannelConfiguration($url);
    }

    /**
     * @param array<string, string> $values Trimmed fields.
     * @param array<string, string> $errors Collected messages, extended by reference.
     * @return TelegramChannelConfiguration|null Configuration, or null when invalid.
     */
    private function telegram(array $values, array &$errors): ?TelegramChannelConfiguration
    {
        $token = $values['bot_token'] ?? '';
        $chat = $values['chat_id'] ?? '';

        if (preg_match(self::TELEGRAM_TOKEN_PATTERN, $token) !== 1) {
            $errors['bot_token'] = 'Enter the bot token issued by BotFather, in the form 123456:ABC….';
        }

        if (preg_match(self::TELEGRAM_CHAT_PATTERN, $chat) !== 1) {
            $errors['chat_id'] = 'Enter a numeric chat ID or a public @channel name.';
        }

        if (isset($errors['bot_token']) || isset($errors['chat_id'])) {
            return null;
        }

        return new TelegramChannelConfiguration($token, $chat);
    }

    /**
     * @param array<string, string> $values Trimmed fields.
     * @param array<string, string> $errors Collected messages, extended by reference.
     * @param ChannelConfiguration|null $current Stored configuration whose signing key is kept.
     * @return WebhookChannelConfiguration|null Configuration, or null when invalid.
     */
    private function webhook(array $values, array &$errors, ?ChannelConfiguration $current): ?WebhookChannelConfiguration
    {
        $url = $this->normalizedUrl($values['url'] ?? '');

        if ($url === null || !str_starts_with($url, 'https://')) {
            $errors['url'] = 'Enter an absolute HTTPS URL without credentials or fragment.';

            return null;
        }

        $secret = $current instanceof WebhookChannelConfiguration ? $current->signingSecret : $this->newSigningSecret();

        return new WebhookChannelConfiguration($url, $secret);
    }

    /**
     * @param array<string, string> $values Trimmed fields.
     * @param array<string, string> $errors Collected messages, extended by reference.
     * @return SmsChannelConfiguration|null Configuration, or null when invalid.
     */
    private function sms(array $values, array &$errors): ?SmsChannelConfiguration
    {
        $phone = str_replace([' ', '-', '(', ')'], '', $values['phone'] ?? '');

        if (preg_match(self::PHONE_PATTERN, $phone) !== 1) {
            $errors['phone'] = 'Enter a phone number in international format, such as +15550001234.';

            return null;
        }

        return new SmsChannelConfiguration($phone);
    }

    /**
     * @param string $value Submitted URL.
     * @return string|null Normalized URL, or null when its syntax is invalid.
     */
    private function normalizedUrl(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            return $this->syntax->url($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
