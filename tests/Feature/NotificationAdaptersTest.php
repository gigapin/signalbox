<?php

declare(strict_types=1);

/** Exercise real adapter bindings with fake HTTP/Mail and deterministic DNS; no external services are contacted. */

use App\Contracts\Notifications\ChannelConfiguration;
use App\Contracts\Notifications\ChannelVerificationSender;
use App\Contracts\Security\HostResolver;
use App\Data\Notifications\ChannelMessage;
use App\Data\Notifications\EmailChannelConfiguration;
use App\Data\Notifications\SlackChannelConfiguration;
use App\Data\Notifications\SmsChannelConfiguration;
use App\Data\Notifications\TelegramChannelConfiguration;
use App\Data\Notifications\WebhookChannelConfiguration;
use App\Enums\Notifications\ChannelType;
use App\Enums\Notifications\DeliveryStatus;
use App\Exceptions\ChannelDeliveryFailed;
use App\Mail\ChannelNotificationMail;
use App\Services\Notifications\AdapterChannelVerificationSender;
use App\Services\Notifications\ChannelAdapterRegistry;
use App\Services\Notifications\NotificationHttpTransport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\FakeHostResolver;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-29 09:00:00', 'UTC'));
    $this->resolver = new FakeHostResolver([
        'hooks.slack.com' => ['93.184.216.34'],
        'api.telegram.org' => ['93.184.216.35'],
        'hooks.example.test' => ['93.184.216.36', '2606:4700:4700::1111'],
    ]);
    app()->instance(HostResolver::class, $this->resolver);
    $this->message = new ChannelMessage(
        '12345678-1234-4234-8234-123456789012',
        'incident.opened',
        CarbonImmutable::now('UTC'),
        'Monitor unavailable',
        'Checkout <ops> & <!channel> is unavailable.',
        ['incident_id' => 42, 'monitor_id' => 7],
    );
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param string $family One of the supported configured families.
 * @return ChannelConfiguration Synthetic destination and credentials; never contact these endpoints.
 */
function adapterConfiguration(string $family): ChannelConfiguration
{
    return match ($family) {
        'email' => new EmailChannelConfiguration('oncall@example.test'),
        'slack' => new SlackChannelConfiguration('https://hooks.slack.com/services/T000/B000/private-slack-key'),
        'telegram' => new TelegramChannelConfiguration('123456:private-bot-token', '-1001234567890'),
        'webhook' => new WebhookChannelConfiguration('https://hooks.example.test/private-hook?key=private-query', str_repeat('a1', 32)),
        'sms' => new SmsChannelConfiguration('+15550001234'),
    };
}

it('binds the real verification sender and sends literal plain-text email through SMTP only', function (): void {
    Mail::fake();
    expect(app(ChannelVerificationSender::class))->toBeInstanceOf(AdapterChannelVerificationSender::class);

    app(ChannelVerificationSender::class)->send('Ops <team>', adapterConfiguration('email'), '654321');

    Mail::assertSent(ChannelNotificationMail::class, function (ChannelNotificationMail $mail): bool {
        expect($mail->message->event)->toBe('channel.verification')
            ->and($mail->message->data)->toBe(['code' => '654321', 'expires_in_seconds' => 900])
            ->and($mail->content()->text)->toBe('mail.channel-notification')
            ->and($mail->content()->view)->toBeNull()
            ->and($mail->envelope()->subject)->toBe('Verify your Signalbox notification channel');

        $mail->assertSeeInText('654321');
        $mail->assertSeeInText('Ops <team>');

        return $mail->hasTo('oncall@example.test') && $mail->mailer === 'smtp';
    });
    Mail::assertSentCount(1);
    Mail::assertNothingQueued();
    Http::assertNothingSent();
    expect(config('mail.mailers.smtp.timeout'))->toBe(5);
});

it('drops SMTP exception details and does not retry', function (): void {
    $mailer = Mockery::mock(\Illuminate\Mail\Mailer::class);
    Mail::shouldReceive('mailer')->once()->with('smtp')->andReturn($mailer);
    $mailer->shouldReceive('to')->once()->with('oncall@example.test')->andReturnSelf();
    $mailer->shouldReceive('send')->once()->andThrow(new TransportException('private-password 654321 provider payload'));

    try {
        app(ChannelVerificationSender::class)->send('Ops', adapterConfiguration('email'), '654321');
        throw new LogicException('SMTP failure was accepted.');
    } catch (ChannelDeliveryFailed $error) {
        expect($error->getMessage())->toBe('The email provider could not accept the notification.')
            ->and($error->getPrevious())->toBeNull();
    }
});

it('posts Slack plain text with mention-safe fallback and accepts only its acknowledgement', function (): void {
    Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);
    $receipt = app(ChannelAdapterRegistry::class)->for(ChannelType::Slack)->send(adapterConfiguration('slack'), $this->message);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request['blocks'][0]['text'] === ['type' => 'plain_text', 'text' => $this->message->text, 'emoji' => false]
            && $request['text'] === 'Checkout &lt;ops&gt; &amp; &lt;!channel&gt; is unavailable.'
            && $request['unfurl_links'] === false
            && $request['unfurl_media'] === false;
    });
    expect($receipt->status())->toBe(DeliveryStatus::Sent);
    Http::assertSentCount(1);
});

it('sends Telegram chat text without markup or previews', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 123]])]);
    $receipt = app(ChannelAdapterRegistry::class)->for(ChannelType::Telegram)->send(adapterConfiguration('telegram'), $this->message);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bot123456:private-bot-token/sendMessage'
        && $request->data() === [
            'chat_id' => '-1001234567890',
            'text' => $this->message->text,
            'link_preview_options' => ['is_disabled' => true],
        ]);
    expect($receipt->status())->toBe(DeliveryStatus::Sent);
});

it('signs exact webhook bytes and changes the timestamp signature on a later attempt while retaining the event identity', function (): void {
    Http::fake(['hooks.example.test/*' => Http::response('', 204)]);
    $adapter = app(ChannelAdapterRegistry::class)->for(ChannelType::Webhook);
    $configuration = adapterConfiguration('webhook');
    $adapter->send($configuration, $this->message);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
    $adapter->send($configuration, $this->message);
    $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0]);

    foreach ($requests as $request) {
        $timestamp = $request->header('X-Signalbox-Timestamp')[0];
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->body(), str_repeat('a1', 32));

        expect($request->header('X-Signalbox-Signature'))->toBe(['v1='.$expected])
            ->and($request->header('X-Signalbox-Id'))->toBe([$this->message->id])
            ->and($request->data())->toBe($this->message->payload())
            ->and($request->hasHeader('Content-Type', 'application/json'))->toBeTrue()
            ->and(hash_hmac('sha256', $timestamp.'.'.$request->body().' ', str_repeat('a1', 32)))->not->toBe($expected)
            ->and(hash_hmac('sha256', $timestamp.'.'.$request->body(), str_repeat('b2', 32)))->not->toBe($expected);
    }

    expect($requests[0]->body())->toBe($requests[1]->body())
        ->and($requests[0]->header('X-Signalbox-Signature'))->not->toBe($requests[1]->header('X-Signalbox-Signature'))
        ->and($requests[0]->header('X-Signalbox-Timestamp'))->toBe(['1793264400'])
        ->and($this->resolver->queries)->toBe(['hooks.example.test', 'hooks.example.test']);
});

it('records SMS metadata only and refuses to verify an undelivered SMS code', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('info')->twice()->with('SMS stub: recorded without sending.', Mockery::on(function (array $context): bool {
        expect($context)->toHaveKeys(['message_id', 'event', 'channel_type'])
            ->and($context)->toHaveCount(3)
            ->and(json_encode($context))->not->toContain('654321', '+15550001234', 'Checkout');

        return $context['channel_type'] === 'sms';
    }));
    app()->instance(LoggerInterface::class, $logger);

    $receipt = app(ChannelAdapterRegistry::class)->for(ChannelType::Sms)->send(adapterConfiguration('sms'), $this->message);
    expect($receipt->status())->toBe(DeliveryStatus::Suppressed)
        ->and($receipt->summary())->toBe('SMS stub: recorded without sending.')
        ->and(fn () => app(ChannelVerificationSender::class)->send('Ops', adapterConfiguration('sms'), '654321'))
        ->toThrow(ChannelDeliveryFailed::class, 'SMS is a recording-only stub');
    Http::assertNothingSent();
});

it('keeps an empty webhook field map as a JSON object and signs UTF-8 bytes with the current key', function (): void {
    Http::fake(['*' => Http::response('', 204)]);
    $message = new ChannelMessage($this->message->id, 'incident.resolved', CarbonImmutable::now(), 'Recovered', 'Checkout è operativo ✅');
    $configuration = new WebhookChannelConfiguration('https://hooks.example.test/', str_repeat('b2', 32));
    app(ChannelAdapterRegistry::class)->for(ChannelType::Webhook)->send($configuration, $message);

    Http::assertSent(function (Request $request): bool {
        expect($request->body())->toContain('"data":{}', 'è operativo ✅');
        $timestamp = $request->header('X-Signalbox-Timestamp')[0];

        return $request->header('X-Signalbox-Signature') === ['v1='.hash_hmac('sha256', $timestamp.'.'.$request->body(), str_repeat('b2', 32))];
    });
});

it('refuses incorrect family configurations before any network call', function (ChannelType $family): void {
    $configuration = $family === ChannelType::Email ? adapterConfiguration('sms') : adapterConfiguration('email');

    expect(fn () => app(ChannelAdapterRegistry::class)->for($family)->send($configuration, $this->message))
        ->toThrow(ChannelDeliveryFailed::class, 'configuration is invalid');
    Http::assertNothingSent();
})->with(ChannelType::cases());

it('does not treat successful HTTP without a provider acknowledgement as delivery', function (string $family, mixed $responseBody): void {
    Http::fake(['*' => Http::response($responseBody, 200)]);
    expect(fn () => app(ChannelAdapterRegistry::class)->for(ChannelType::from($family))->send(adapterConfiguration($family), $this->message))
        ->toThrow(ChannelDeliveryFailed::class, 'did not acknowledge');
    Http::assertSentCount(1);
})->with([
    ['slack', 'invalid_payload private-slack-key 654321'],
    ['slack', ''],
    ['telegram', ['ok' => false, 'description' => 'private-bot-token 654321']],
    ['telegram', ['ok' => 'true']],
    ['telegram', ['result' => []]],
    ['telegram', 'not json'],
]);

it('refuses HTTP errors and redirects without following or retrying', function (string $family, int $status): void {
    Http::fake(['*' => Http::response('private provider body 654321', $status, ['Location' => 'https://other.example.test/steal'])]);

    try {
        app(ChannelAdapterRegistry::class)->for(ChannelType::from($family))->send(adapterConfiguration($family), $this->message);
        throw new LogicException('Provider failure was accepted.');
    } catch (ChannelDeliveryFailed $error) {
        expect($error->getMessage())->toBe('The notification provider refused the request (HTTP '.$status.').')
            ->and($error->getPrevious())->toBeNull();
    }

    Http::assertSentCount(1);
    expect($this->resolver->queries)->toHaveCount(1);
})->with(['slack', 'telegram', 'webhook'])->with([301, 302, 303, 307, 308, 400, 401, 403, 404, 429, 500, 503]);

it('refuses private or mixed DNS answers for each HTTP family before sending', function (string $family, array $addresses): void {
    app()->instance(HostResolver::class, new FakeHostResolver([
        'hooks.slack.com' => $addresses,
        'api.telegram.org' => $addresses,
        'hooks.example.test' => $addresses,
    ]));
    Http::fake();

    expect(fn () => app(ChannelAdapterRegistry::class)->for(ChannelType::from($family))->send(adapterConfiguration($family), $this->message))
        ->toThrow(ChannelDeliveryFailed::class, 'refused by outbound security');
    Http::assertNothingSent();
})->with(['slack', 'telegram', 'webhook'])->with([
    'loopback' => [['127.0.0.1']],
    'metadata' => [['169.254.169.254']],
    'mixed' => [['93.184.216.34', '::1']],
    'no answer' => [[]],
]);

it('re-resolves and refuses a destination that rebinds after a successful attempt', function (): void {
    Http::fake(['hooks.example.test/*' => Http::response('', 204)]);
    $resolver = new class () implements HostResolver {
        /** @var int Number of authorization-time lookups performed. */
        public int $calls = 0;

        /**
         * @param string $host Synthetic customer endpoint.
         * @param int|null $timeoutMs Unused by the deterministic immediate answer.
         * @return list<string> Public on the first attempt, private on the second.
         */
        public function resolve(string $host, ?int $timeoutMs = null): array
        {
            return ++$this->calls === 1 ? ['93.184.216.36'] : ['127.0.0.1'];
        }
    };
    app()->instance(HostResolver::class, $resolver);
    $adapter = app(ChannelAdapterRegistry::class)->for(ChannelType::Webhook);
    $adapter->send(adapterConfiguration('webhook'), $this->message);

    expect(fn () => $adapter->send(adapterConfiguration('webhook'), $this->message))
        ->toThrow(ChannelDeliveryFailed::class, 'refused by outbound security');
    Http::assertSentCount(1);
    expect($resolver->calls)->toBe(2);
});

it('keeps HTTPS verification, fresh pins, proxy bypass and strict transfer bounds', function (): void {
    Http::fake(function (Request $request, array $options) {
        expect($options['allow_redirects'])->toBeFalse()
            ->and($options['verify'])->toBeTrue()
            ->and($options['cookies'])->toBeFalse()
            ->and($options['proxy'])->toBe('')
            ->and($options['timeout'])->toBe(5)
            ->and($options['connect_timeout'])->toBe(2)
            ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['hooks.example.test:443:93.184.216.36,[2606:4700:4700::1111]'])
            ->and($options['curl'][CURLOPT_FRESH_CONNECT])->toBeTrue()
            ->and($options['curl'][CURLOPT_FORBID_REUSE])->toBeTrue()
            ->and($options['curl'][CURLOPT_PROXY])->toBe('')
            ->and($options['curl'][CURLOPT_NOPROXY])->toBe('*')
            ->and(is_resource($options['sink']))->toBeTrue();

        return Http::response('', 204);
    });
    app(ChannelAdapterRegistry::class)->for(ChannelType::Webhook)->send(adapterConfiguration('webhook'), $this->message);
});

it('discards connection error secrets and attempts delivery only once', function (string $family): void {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        throw new ConnectionException('private-slack-key private-bot-token private-query 654321');
    });

    try {
        app(ChannelAdapterRegistry::class)->for(ChannelType::from($family))->send(adapterConfiguration($family), $this->message);
        throw new LogicException('Connection failure was accepted.');
    } catch (ChannelDeliveryFailed $error) {
        expect($error->getMessage())->toBe('The notification provider could not be reached within the connection limits.')
            ->and($error->getPrevious())->toBeNull()
            ->and($calls)->toBe(1);
    }
})->with(['slack', 'telegram', 'webhook']);

it('aborts an oversized decoded acknowledgement instead of silently accepting truncated content', function (): void {
    Http::fake(['*' => Http::response(str_repeat('x', 16385), 200)]);

    expect(fn () => app(ChannelAdapterRegistry::class)->for(ChannelType::Webhook)->send(adapterConfiguration('webhook'), $this->message))
        ->toThrow(ChannelDeliveryFailed::class, 'exceeded the size limit');
    Http::assertSentCount(1);
});

it('refuses cleartext endpoints and invalid content without an HTTP call', function (): void {
    Http::fake();
    $http = app(NotificationHttpTransport::class);

    expect(fn () => $http->post('http://hooks.example.test/', '{}'))->toThrow(ChannelDeliveryFailed::class, 'must use HTTPS')
        ->and(fn () => $http->encode(['text' => "\xff"]))->toThrow(ChannelDeliveryFailed::class, 'could not be encoded');
    Http::assertNothingSent();
});

it('accepts webhook 2xx responses at the exact response boundary', function (int $status): void {
    Http::fake(['*' => Http::response(str_repeat('x', 16384), $status)]);
    $receipt = app(ChannelAdapterRegistry::class)->for(ChannelType::Webhook)->send(adapterConfiguration('webhook'), $this->message);

    expect($receipt->status())->toBe(DeliveryStatus::Sent);
    Http::assertSentCount(1);
})->with([200, 201, 202, 299]);

it('requires the provider-specific HTTP 200 acknowledgement', function (string $family): void {
    Http::fake(['*' => Http::response($family === 'slack' ? 'ok' : ['ok' => true], 201)]);

    expect(fn () => app(ChannelAdapterRegistry::class)->for(ChannelType::from($family))->send(adapterConfiguration($family), $this->message))
        ->toThrow(ChannelDeliveryFailed::class, 'did not acknowledge');
})->with(['slack', 'telegram']);

it('rejects invalid operational limits instead of creating an unbounded transfer', function (string $setting, mixed $value): void {
    config(['notifications.http.'.$setting => $value]);
    Http::fake();

    expect(fn () => app(ChannelAdapterRegistry::class)->for(ChannelType::Webhook)->send(adapterConfiguration('webhook'), $this->message))
        ->toThrow(InvalidArgumentException::class, 'HTTP limits are invalid');
    Http::assertNothingSent();
})->with([
    ['timeout_seconds', 0],
    ['timeout_seconds', 11],
    ['timeout_seconds', '5'],
    ['connect_timeout_seconds', 0],
    ['connect_timeout_seconds', 6],
    ['response_max_bytes', 1023],
    ['response_max_bytes', 65537],
    ['response_max_bytes', null],
]);
