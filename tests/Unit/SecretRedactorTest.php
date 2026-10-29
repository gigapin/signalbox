<?php

declare(strict_types=1);

/** Verify redaction of URLs, headers, bodies, messages, log records and the safe configuration projection. */

use App\Data\Monitoring\DnsMonitorConfiguration;
use App\Data\Monitoring\HeartbeatMonitorConfiguration;
use App\Data\Monitoring\HttpMonitorConfiguration;
use App\Data\Monitoring\KeywordMonitorConfiguration;
use App\Data\Monitoring\TlsMonitorConfiguration;
use App\Enums\Monitoring\DnsRecordType;
use App\Enums\Monitoring\HttpMethod;
use App\Enums\Monitoring\KeywordMode;
use App\Logging\SecretRedactionProcessor;
use App\Services\Monitoring\MonitorConfigurationProjection;
use App\Services\Security\SecretRedactor;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

beforeEach(function (): void {
    $this->redactor = new SecretRedactor();
});

it('redacts every query value while keeping the target recognizable', function (string $url, string $expected): void {
    expect($this->redactor->url($url))->toBe($expected);
})->with([
    'named values' => ['https://api.example/v1/ping?token=abc&mode=fast', 'https://api.example/v1/ping?token=[REDACTED]&mode=[REDACTED]'],
    'bare value' => ['https://api.example/?abc123', 'https://api.example/?[REDACTED]'],
    'empty value' => ['https://api.example/?key=', 'https://api.example/?key=[REDACTED]'],
    'no query' => ['http://api.example:8080/health', 'http://api.example:8080/health'],
    'userinfo and fragment removed' => ['https://user:pass@api.example/x#frag', 'https://api.example/x'],
    'IPv6 literal' => ['http://[2001:db8::1]:81/?k=v', 'http://[2001:db8::1]:81/?k=[REDACTED]'],
    'unparseable' => ['not a url', '[REDACTED]'],
]);

it('keeps header names and only content-negotiation values', function (): void {
    expect($this->redactor->headers([
        'authorization' => 'Bearer secret',
        'x-api-key' => 'secret',
        'Content-Type' => 'application/json',
        'accept' => 'text/plain',
    ]))->toBe([
        'authorization' => '[REDACTED]',
        'x-api-key' => '[REDACTED]',
        'Content-Type' => 'application/json',
        'accept' => 'text/plain',
    ]);
});

it('describes bodies by presence and byte size only', function (): void {
    expect($this->redactor->body(null))->toBe(['present' => false, 'bytes' => 0])
        ->and($this->redactor->body(''))->toBe(['present' => true, 'bytes' => 0])
        ->and($this->redactor->body('é secret'))->toBe(['present' => true, 'bytes' => 9]);
});

it('removes known secrets and embedded URL queries from free text', function (): void {
    $message = 'cURL error 28: timeout for https://api.example/hook/s3cr3t-path?sig=abcdef after 5000 ms (Bearer tok-long, tok)';

    expect($this->redactor->message($message, ['s3cr3t-path', 'tok', 'tok-long', '']))
        ->toBe('cURL error 28: timeout for https://api.example/hook/[REDACTED]?sig=[REDACTED] after 5000 ms (Bearer [REDACTED], [REDACTED])');
});

it('redacts nested context by key and by embedded URL without touching objects', function (): void {
    $object = new stdClass();

    expect($this->redactor->context([
        'monitor_id' => 7,
        'password' => 'p',
        'headers' => ['authorization' => 'x'],
        'Body' => 'payload',
        'api_key' => 'k',
        'webhook_url' => 'https://hooks.example/?token=t',
        'nested' => ['access_token' => 't', 'note' => 'see https://a.example/?q=1', 'list' => ['https://b.example/?x=y']],
        'exception' => $object,
        'elapsed_ms' => 12.5,
    ]))->toBe([
        'monitor_id' => 7,
        'password' => '[REDACTED]',
        'headers' => '[REDACTED]',
        'Body' => '[REDACTED]',
        'api_key' => '[REDACTED]',
        'webhook_url' => 'https://hooks.example/?token=[REDACTED]',
        'nested' => ['access_token' => '[REDACTED]', 'note' => 'see https://a.example/?q=[REDACTED]', 'list' => ['https://b.example/?x=[REDACTED]']],
        'exception' => $object,
        'elapsed_ms' => 12.5,
    ]);
});

it('redacts log records before handlers receive them', function (): void {
    $handler = new TestHandler();
    $logger = new Logger('test', [$handler], [new SecretRedactionProcessor(new SecretRedactor())]);

    $logger->warning('Probe failed for https://api.example/?token=abc', ['authorization' => 'Bearer abc', 'url' => 'https://api.example/?k=v']);

    $record = $handler->getRecords()[0];

    expect($record->message)->toBe('Probe failed for https://api.example/?token=[REDACTED]')
        ->and($record->context)->toBe(['authorization' => '[REDACTED]', 'url' => 'https://api.example/?k=[REDACTED]']);
});

it('projects every configuration type without private values', function (): void {
    $projection = new MonitorConfigurationProjection(new SecretRedactor());
    $http = new HttpMonitorConfiguration(
        'https://api.example/health?token=secret',
        HttpMethod::POST,
        ['x-token' => 'secret', 'authorization' => 'Bearer secret'],
        '{"secret":true}',
        204,
        true,
    );

    $projected = $projection->project($http);

    expect($projected)->toBe([
        'type' => 'http',
        'url' => 'https://api.example/health?token=[REDACTED]',
        'method' => 'POST',
        'header_names' => ['authorization', 'x-token'],
        'body' => ['present' => true, 'bytes' => 15],
        'expected_status' => 204,
        'follow_redirects' => true,
    ])->and(json_encode($projected))->not->toContain('secret');

    expect($projection->project(new KeywordMonitorConfiguration($http, 'ok', KeywordMode::Absent)))
        ->toMatchArray(['type' => 'keyword', 'expected_keyword' => 'ok', 'keyword_mode' => 'absent', 'url' => 'https://api.example/health?token=[REDACTED]'])
        ->and($projection->project(new TlsMonitorConfiguration('api.example', 443, 14)))
        ->toBe(['type' => 'tls', 'tls_host' => 'api.example', 'tls_port' => 443, 'tls_expiry_warning_days' => 14])
        ->and($projection->project(new DnsMonitorConfiguration('api.example', DnsRecordType::A, ['93.184.215.14'], '9.9.9.9')))
        ->toBe(['type' => 'dns', 'dns_name' => 'api.example', 'dns_record_type' => 'A', 'dns_expected_values' => ['93.184.215.14'], 'dns_resolver' => '9.9.9.9'])
        ->and($projection->project(new HeartbeatMonitorConfiguration(60)))
        ->toBe(['type' => 'heartbeat', 'heartbeat_grace_seconds' => 60]);
});

it('hides private DTO arguments from stack traces', function (): void {
    try {
        new HttpMonitorConfiguration('https://api.example/?token=trace-secret', HttpMethod::GET, ['x' => 'trace-secret'], 'trace-secret', 99);
    } catch (InvalidArgumentException $exception) {
        expect($exception->getTraceAsString())->not->toContain('trace-secret');

        return;
    }

    throw new LogicException('The invalid configuration was accepted.');
});
