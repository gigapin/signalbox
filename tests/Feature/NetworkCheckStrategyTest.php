<?php

declare(strict_types=1);

/** Verify pure TLS expiry and exact DNS set semantics with controlled UTC time. */

use App\Data\Monitoring\DnsMonitorConfiguration;
use App\Data\Monitoring\TlsMonitorConfiguration;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\DnsRecordType;
use App\Enums\Monitoring\MonitorStatus;
use App\Services\Monitoring\Checks\DnsStrategy;
use App\Services\Monitoring\Checks\TlsStrategy;
use Carbon\CarbonImmutable;
use Tests\Support\CheckInputFixture;

it('evaluates TLS validity and the exact warning boundary', function (?int $days, int $seconds, ?CheckError $error): void {
    $now = CarbonImmutable::parse('2026-10-26T12:00:00Z');
    $input = CheckInputFixture::make(configuration: new TlsMonitorConfiguration('tls.example.test', 443, $days));
    $expiry = $now->addSeconds($seconds);
    $result = app(TlsStrategy::class)->evaluate($input, $expiry, $now, 5.5);

    expect($result->error)->toBe($error)
        ->and($result->status)->toBe($error === null ? MonitorStatus::Up : MonitorStatus::Down)
        ->and($result->tlsExpiresAt)->toEqual($expiry)
        ->and($result->statusCode)->toBeNull()
        ->and($result->latencyMs)->toBe(5.5);
})->with([
    'ordinary validity' => [null, 1, null],
    'expired' => [null, -1, CheckError::Tls],
    'expiry instant' => [null, 0, CheckError::Tls],
    'zero window' => [0, 1, null],
    'before warning' => [1, 86401, null],
    'warning boundary' => [1, 86400, CheckError::TlsExpiring],
    'inside warning' => [1, 86399, CheckError::TlsExpiring],
]);

it('compares complete DNS sets with canonical expectations', function (DnsRecordType $type, array $expected, array $actual, ?CheckError $error): void {
    $input = CheckInputFixture::make(configuration: new DnsMonitorConfiguration('dns.example.test', $type, $expected));
    $result = app(DnsStrategy::class)->evaluate($input, $actual, CarbonImmutable::now('UTC'), 2);

    expect($result->error)->toBe($error)
        ->and($result->tlsExpiresAt)->toBeNull()
        ->and($result->statusCode)->toBeNull();
})->with([
    'A reordered duplicates' => [DnsRecordType::A, ['8.8.8.8', '1.1.1.1'], ['1.1.1.1', '8.8.8.8', '8.8.8.8'], null],
    'AAAA canonical' => [DnsRecordType::AAAA, ['2001:4860:4860:0:0:0:0:8888'], ['2001:4860:4860::8888'], null],
    'CNAME canonical' => [DnsRecordType::CNAME, ['TARGET.EXAMPLE.TEST.'], ['target.example.test'], null],
    'MX canonical' => [DnsRecordType::MX, ['10 MAIL.EXAMPLE.TEST.'], ['10 mail.example.test'], null],
    'null MX' => [DnsRecordType::MX, ['0 .'], ['0 .'], null],
    'TXT exact' => [DnsRecordType::TXT, [" a\tB "], [" a\tB "], null],
    'TXT case-sensitive' => [DnsRecordType::TXT, ['Hello'], ['hello'], CheckError::DnsMismatch],
    'extra record' => [DnsRecordType::A, ['8.8.8.8'], ['8.8.8.8', '1.1.1.1'], CheckError::DnsMismatch],
    'missing record' => [DnsRecordType::A, ['8.8.8.8', '1.1.1.1'], ['8.8.8.8'], CheckError::DnsMismatch],
    'empty answer' => [DnsRecordType::A, ['8.8.8.8'], [], CheckError::DnsMissing],
]);

it('rejects unsupported configuration variants in pure strategies', function (): void {
    $now = CarbonImmutable::now('UTC');
    expect(fn () => app(TlsStrategy::class)->evaluate(CheckInputFixture::make(), $now, $now, 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(DnsStrategy::class)->evaluate(CheckInputFixture::make(), [], $now, 1))->toThrow(InvalidArgumentException::class);
});
