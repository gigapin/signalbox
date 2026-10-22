<?php

declare(strict_types=1);

/** Verify immutable configuration structure without Laravel, persistence or network calls. */

use App\Contracts\Monitoring\MonitorConfiguration;
use App\Data\Monitoring\DnsMonitorConfiguration;
use App\Data\Monitoring\HeartbeatMonitorConfiguration;
use App\Data\Monitoring\HttpMonitorConfiguration;
use App\Data\Monitoring\KeywordMonitorConfiguration;
use App\Data\Monitoring\MonitorRegions;
use App\Data\Monitoring\TlsMonitorConfiguration;
use App\Enums\Monitoring\DnsRecordType;
use App\Enums\Monitoring\HttpMethod;
use App\Enums\Monitoring\KeywordMode;
use App\Enums\Monitoring\MonitorRegion;
use Tests\Support\MonitorConfigurationFixture;

it('represents every supported configuration with its own immutable variant', function (MonitorConfiguration $configuration): void {
    expect((new ReflectionClass($configuration))->isReadOnly())->toBeTrue();
    expect($configuration->type()->value)->toBeIn(['http', 'keyword', 'tls', 'dns', 'heartbeat']);
})->with(MonitorConfigurationFixture::cases());

it('uses the agreed HTTP and keyword defaults without changing meaningful text', function (): void {
    $http = new HttpMonitorConfiguration('https://example.invalid');
    $keyword = new KeywordMonitorConfiguration($http, ' ');
    expect($http->method)->toBe(HttpMethod::GET)->and($http->expectedStatus)->toBe(200)
        ->and($http->followRedirects)->toBeFalse()->and($http->headers)->toBe([])->and($http->body)->toBeNull()
        ->and($keyword->http)->toBe($http)->and($keyword->mode)->toBe(KeywordMode::Present)
        ->and($keyword->keyword)->toBe(' ');
});

it('rejects malformed configuration structure before any persistence', function (Closure $invalid): void {
    expect($invalid)->toThrow(InvalidArgumentException::class);
})->with([
    'empty URL' => [fn () => new HttpMonitorConfiguration(' ')],
    'status below range' => [fn () => new HttpMonitorConfiguration('target', expectedStatus: 99)],
    'status above range' => [fn () => new HttpMonitorConfiguration('target', expectedStatus: 600)],
    'numeric header name' => [fn () => new HttpMonitorConfiguration('target', headers: ['value'])],
    'empty header name' => [fn () => new HttpMonitorConfiguration('target', headers: ['' => 'value'])],
    'nonscalar header value' => [fn () => new HttpMonitorConfiguration('target', headers: ['X-Probe' => []])],
    'empty keyword' => [fn () => new KeywordMonitorConfiguration(new HttpMonitorConfiguration('target'), '')],
    'empty TLS host' => [fn () => new TlsMonitorConfiguration('', 443)],
    'TLS port zero' => [fn () => new TlsMonitorConfiguration('host', 0)],
    'TLS port above range' => [fn () => new TlsMonitorConfiguration('host', 65536)],
    'negative expiry warning' => [fn () => new TlsMonitorConfiguration('host', 443, -1)],
    'empty DNS name' => [fn () => new DnsMonitorConfiguration('', DnsRecordType::A, ['192.0.2.1'])],
    'empty DNS expectations' => [fn () => new DnsMonitorConfiguration('host', DnsRecordType::A, [])],
    'empty DNS value' => [fn () => new DnsMonitorConfiguration('host', DnsRecordType::TXT, [''])],
    'nonscalar DNS value' => [fn () => new DnsMonitorConfiguration('host', DnsRecordType::TXT, [false])],
    'associative DNS values' => [fn () => new DnsMonitorConfiguration('host', DnsRecordType::TXT, ['value' => 'text'])],
    'empty resolver' => [fn () => new DnsMonitorConfiguration('host', DnsRecordType::A, ['192.0.2.1'], '')],
    'negative heartbeat tolerance' => [fn () => new HeartbeatMonitorConfiguration(-1)],
]);

it('preserves boundary values and nullable optional settings', function (): void {
    expect((new HttpMonitorConfiguration('target', body: '', expectedStatus: 100))->body)->toBe('')
        ->and((new HttpMonitorConfiguration('target', expectedStatus: 599))->expectedStatus)->toBe(599)
        ->and((new TlsMonitorConfiguration('host', 1))->expiryWarningDays)->toBeNull()
        ->and((new TlsMonitorConfiguration('host', 65535, 0))->port)->toBe(65535)
        ->and((new DnsMonitorConfiguration('host', DnsRecordType::A, ['192.0.2.1']))->resolver)->toBeNull()
        ->and((new HeartbeatMonitorConfiguration(0))->graceSeconds)->toBe(0);
});

it('defaults to EU and canonicalizes explicit region order', function (): void {
    expect((new MonitorRegions())->toValues())->toBe(['eu'])
        ->and((new MonitorRegions([MonitorRegion::Us, MonitorRegion::Eu]))->toValues())->toBe(['eu', 'us'])
        ->and(MonitorRegions::fromValues(['us'])->values)->toBe([MonitorRegion::Us]);
});

it('rejects empty duplicate unknown and malformed region selections', function (array $values): void {
    expect(fn () => MonitorRegions::fromValues($values))->toThrow(InvalidArgumentException::class);
})->with([
    [[]], [['eu', 'eu']], [['apac']], [[null]], [['first' => 'eu']],
]);

it('requires actual enum values when constructing a typed region set', function (): void {
    expect(fn () => new MonitorRegions(['eu']))->toThrow(InvalidArgumentException::class);
    expect(fn () => new MonitorRegions([MonitorRegion::Eu, MonitorRegion::Eu]))->toThrow(InvalidArgumentException::class);
});
