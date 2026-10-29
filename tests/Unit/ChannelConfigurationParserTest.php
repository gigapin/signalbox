<?php

declare(strict_types=1);

/** Verify notification channel parsing, masking and secret retention without booting the application. */

use App\Data\Notifications\EmailChannelConfiguration;
use App\Data\Notifications\SlackChannelConfiguration;
use App\Data\Notifications\SmsChannelConfiguration;
use App\Data\Notifications\TelegramChannelConfiguration;
use App\Data\Notifications\WebhookChannelConfiguration;
use App\Enums\Notifications\ChannelType;
use App\Exceptions\ChannelConfigurationInvalid;
use App\Services\Monitoring\MonitorInputLimits;
use App\Services\Monitoring\MonitorInputSyntax;
use App\Services\Notifications\ChannelConfigurationParser;
use Illuminate\Config\Repository;

beforeEach(function (): void {
    $limits = new MonitorInputLimits(new Repository(['monitor-input' => require __DIR__.'/../../config/monitor-input.php']));
    $this->parser = new ChannelConfigurationParser(new MonitorInputSyntax($limits));
});

/**
 * @param ChannelConfigurationParser $parser Parser under test.
 * @param ChannelType $type Channel family.
 * @param array<string, string> $input Submitted fields.
 * @return array<string, string> Field errors raised by the parser; empty when parsing succeeds.
 */
function ccpErrors(ChannelConfigurationParser $parser, ChannelType $type, array $input): array
{
    try {
        $parser->parse($type, $input);
    } catch (ChannelConfigurationInvalid $exception) {
        return $exception->errors;
    }

    return [];
}

it('normalizes each channel family and masks its destination', function (): void {
    $email = $this->parser->parse(ChannelType::Email, ['address' => ' OnCall@Example.TEST ']);
    $slack = $this->parser->parse(ChannelType::Slack, ['webhook_url' => 'https://hooks.slack.com/services/T0/B0/abcdWXYZ']);
    $telegram = $this->parser->parse(ChannelType::Telegram, ['bot_token' => '123456:'.str_repeat('A', 35), 'chat_id' => '-1001234567890']);
    $webhook = $this->parser->parse(ChannelType::Webhook, ['url' => 'https://Hooks.Example.test:8443/in?token=s3cret']);
    $sms = $this->parser->parse(ChannelType::Sms, ['phone' => '+39 333 123-4567']);

    expect($email)->toBeInstanceOf(EmailChannelConfiguration::class)
        ->and($email->toArray())->toBe(['address' => 'oncall@example.test'])
        ->and($email->hint())->toBe('o•••@example.test')
        ->and($slack)->toBeInstanceOf(SlackChannelConfiguration::class)
        ->and($slack->hint())->toBe('hooks.slack.com/services/…WXYZ')
        ->and($slack->editable())->toBe([])
        ->and($telegram)->toBeInstanceOf(TelegramChannelConfiguration::class)
        ->and($telegram->hint())->toBe('Chat …7890')
        ->and($telegram->editable())->toBe(['chat_id' => '-1001234567890'])
        ->and($webhook)->toBeInstanceOf(WebhookChannelConfiguration::class)
        ->and($webhook->toArray()['url'])->toBe('https://hooks.example.test:8443/in?token=s3cret')
        ->and($webhook->toArray()['signing_secret'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($webhook->hint())->toBe('https://hooks.example.test:8443/…')
        ->and($webhook->hint())->not->toContain('s3cret')
        ->and($sms)->toBeInstanceOf(SmsChannelConfiguration::class)
        ->and($sms->toArray())->toBe(['phone' => '+393331234567'])
        ->and($sms->hint())->toBe('+•••4567');
});

it('rejects malformed fields with one message per field and never echoes the input', function (ChannelType $type, array $input, array $fields, string $marker): void {
    $errors = ccpErrors($this->parser, $type, $input);

    expect(array_keys($errors))->toBe($fields);

    foreach ($errors as $message) {
        expect($message)->not->toContain($marker);
    }
})->with([
    'email without domain' => [ChannelType::Email, ['address' => 'oncall-zq'], ['address'], 'oncall-zq'],
    'email missing' => [ChannelType::Email, [], ['address'], 'oncall-zq'],
    'slack on another host' => [ChannelType::Slack, ['webhook_url' => 'https://evil.example/services/T0/B0/zq'], ['webhook_url'], 'evil.example'],
    'slack over http' => [ChannelType::Slack, ['webhook_url' => 'http://hooks.slack.com/services/T0/B0/zq'], ['webhook_url'], 'B0/zq'],
    'slack bare prefix' => [ChannelType::Slack, ['webhook_url' => 'https://hooks.slack.com/services/'], ['webhook_url'], 'zq'],
    'telegram both wrong' => [ChannelType::Telegram, ['bot_token' => 'token-zq', 'chat_id' => 'room-zq'], ['bot_token', 'chat_id'], 'zq'],
    'webhook over http' => [ChannelType::Webhook, ['url' => 'http://hooks.example.test/zq'], ['url'], 'hooks.example.test'],
    'webhook with userinfo' => [ChannelType::Webhook, ['url' => 'https://user:pass-zq@hooks.example.test/in'], ['url'], 'pass-zq'],
    'sms without plus' => [ChannelType::Sms, ['phone' => '3331234567'], ['phone'], '3331234567'],
    'sms leading zero' => [ChannelType::Sms, ['phone' => '+0331234567'], ['phone'], '0331234567'],
]);

it('keeps stored secrets when an update leaves them blank and replaces them when given', function (): void {
    $token = '123456:'.str_repeat('B', 35);
    $current = $this->parser->parse(ChannelType::Telegram, ['bot_token' => $token, 'chat_id' => '42']);

    $renamedChat = $this->parser->parse(ChannelType::Telegram, ['bot_token' => '', 'chat_id' => '43'], $current);
    $newToken = $this->parser->parse(ChannelType::Telegram, ['bot_token' => '654321:'.str_repeat('C', 35), 'chat_id' => '42'], $current);
    $unchanged = $this->parser->parse(ChannelType::Telegram, ['chat_id' => '42'], $current);

    expect($renamedChat->toArray())->toBe(['bot_token' => $token, 'chat_id' => '43'])
        ->and($renamedChat->sameAs($current))->toBeFalse()
        ->and($newToken->sameAs($current))->toBeFalse()
        ->and($unchanged->sameAs($current))->toBeTrue();
});

it('keeps the webhook signing key across URL changes', function (): void {
    $current = $this->parser->parse(ChannelType::Webhook, ['url' => 'https://hooks.example.test/a']);
    $moved = $this->parser->parse(ChannelType::Webhook, ['url' => 'https://hooks.example.test/b'], $current);
    $kept = $this->parser->parse(ChannelType::Webhook, ['url' => ''], $current);

    expect($moved)->toBeInstanceOf(WebhookChannelConfiguration::class)
        ->and($moved->toArray()['signing_secret'])->toBe($current->toArray()['signing_secret'])
        ->and($moved->sameAs($current))->toBeFalse()
        ->and($kept->sameAs($current))->toBeTrue();
});

it('does not keep a blank non-secret field', function (): void {
    $current = $this->parser->parse(ChannelType::Email, ['address' => 'a@example.test']);

    expect(fn () => $this->parser->parse(ChannelType::Email, ['address' => ''], $current))
        ->toThrow(ChannelConfigurationInvalid::class);
});

it('refuses to change the type of a stored configuration', function (): void {
    $current = $this->parser->parse(ChannelType::Email, ['address' => 'a@example.test']);

    expect(fn () => $this->parser->parse(ChannelType::Sms, ['phone' => '+15550001234'], $current))
        ->toThrow(LogicException::class);
});

it('hydrates stored values and rejects incomplete stored objects', function (): void {
    $stored = $this->parser->hydrate(ChannelType::Slack, ['webhook_url' => 'https://hooks.slack.com/services/T/B/c']);

    expect($stored)->toBeInstanceOf(SlackChannelConfiguration::class)
        ->and(fn () => $this->parser->hydrate(ChannelType::Telegram, ['bot_token' => 'x']))->toThrow(LogicException::class);
});
