<?php

declare(strict_types=1);

/** Verify complete candidates and explicit patch semantics without persistence or HTTP transport. */

use App\Data\Monitoring\MonitorInput;
use App\Enums\Monitoring\MonitorType;
use App\Services\Monitoring\MonitorInputValidator;
use Illuminate\Validation\ValidationException;
use Tests\Support\MonitorInputFixture;

it('builds each validated type using the public input contract', function (array $payload): void {
    $result = app(MonitorInputValidator::class)->validate($payload);
    expect($result->data)->toBeInstanceOf(MonitorInput::class)
        ->and($result->data->configuration->type()->value)->toBe($payload['type'])
        ->and($result->data->regions->toValues())->toBe(['eu'])
        ->and($result->data->confirmationThreshold)->toBe(3)
        ->and($result->patch->values)->not->toHaveKey('confirmation_threshold')
        ->and($result->patch->values)->not->toHaveKey('regions');
})->with(MonitorInputFixture::cases());

it('normalizes safe metadata while preserving exact sensitive bytes', function (): void {
    $result = app(MonitorInputValidator::class)->validate(MonitorInputFixture::http());
    $values = $result->data->candidate();
    expect($result->data->name)->toBe('Synthetic input')
        ->and($result->data->intervalSeconds)->toBe(300)
        ->and($values['url'])->toBe('https://example.invalid/Path?key=synthetic-secret')
        ->and($values['headers'])->toBe(['authorization' => ' Bearer synthetic-secret ', 'x.probe' => ''])
        ->and($values['body'])->toBe(' exact body ');
});

it('rejects invalid scalar bounds collection shapes and protected fields', function (array $changes): void {
    expect(fn () => app(MonitorInputValidator::class)->validate([...MonitorInputFixture::http(), ...$changes]))->toThrow(ValidationException::class);
})->with([
    [['name' => ' ']], [['name' => str_repeat('a', 256)]], [['type' => null]], [['type' => 'unsupported']],
    [['interval_seconds' => 29]], [['interval_seconds' => 86401]], [['interval_seconds' => 30.0]],
    [['interval_seconds' => '3e2']], [['interval_seconds' => true]], [['interval_seconds' => []]],
    [['timeout_ms' => 99]], [['timeout_ms' => 10001]], [['confirmation_threshold' => 0]], [['confirmation_threshold' => 11]],
    [['regions' => []]], [['regions' => null]], [['regions' => ['eu', 'eu']]], [['regions' => ['apac']]], [['regions' => ['first' => 'eu']]],
    [['headers' => null]], [['method' => 'CONNECT']], [['expected_status' => 600]], [['follow_redirects' => 'true']],
    [['body' => false]], [['body' => str_repeat('a', 65537)]], [['method' => 'GET']],
    [['team_id' => null]], [['id' => 1]], [['last_status' => 'up']], [['next_check_at' => null]], [['is_paused' => false]],
    [['is_plan_suspended' => false]], [['headers_json' => []]], [['regions_json' => ['eu']]], [['unknown' => 'value']],
    [['tls_host' => null]], [['dns_name' => null]], [['heartbeat_grace_seconds' => null]],
]);

it('accepts inclusive operational bounds and normalizes form primitives', function (): void {
    $validator = app(MonitorInputValidator::class);
    $low = $validator->validate([...MonitorInputFixture::http(), 'interval_seconds' => 30, 'timeout_ms' => 100, 'confirmation_threshold' => 1, 'follow_redirects' => '0']);
    $high = $validator->validate([...MonitorInputFixture::http(), 'interval_seconds' => 86400, 'timeout_ms' => 10000, 'confirmation_threshold' => 10, 'follow_redirects' => '1', 'regions' => ['us', 'eu']]);
    expect($low->data->timeoutMs)->toBe(100)->and($low->data->candidate()['follow_redirects'])->toBeFalse()
        ->and($high->data->intervalSeconds)->toBe(86400)->and($high->data->confirmationThreshold)->toBe(10)
        ->and($high->data->candidate()['follow_redirects'])->toBeTrue()
        ->and($high->patch->values['regions'])->toBe(['eu', 'us']);
});

it('measures body and keyword limits by bytes while preserving whitespace and empty body', function (): void {
    $validator = app(MonitorInputValidator::class);
    $payload = MonitorInputFixture::cases()['keyword'][0];
    $result = $validator->validate([...$payload, 'body' => str_repeat('é', 32768), 'expected_keyword' => str_repeat('é', 2048)]);
    expect(strlen($result->data->candidate()['body']))->toBe(65536)
        ->and(strlen($result->data->candidate()['expected_keyword']))->toBe(4096);
    expect(fn () => $validator->validate([...$payload, 'expected_keyword' => str_repeat('é', 2048).'x']))->toThrow(ValidationException::class);
    expect(fn () => $validator->validate([...$payload, 'expected_keyword' => '']))->toThrow(ValidationException::class);
    expect($validator->validate([...$payload, 'body' => '', 'expected_keyword' => ' '])->data->candidate()['expected_keyword'])->toBe(' ');
});

it('preserves omitted settings and secrets without copying them into the explicit patch', function (): void {
    $validator = app(MonitorInputValidator::class);
    $current = $validator->validate(MonitorInputFixture::http())->data;
    $result = $validator->validate(['name' => ' Renamed '], $current);
    expect($result->data->configuration)->toEqual($current->configuration)
        ->and($result->patch->values)->toBe(['name' => 'Renamed']);
});

it('distinguishes explicit null and empty body and replaces header maps wholesale', function (): void {
    $validator = app(MonitorInputValidator::class);
    $current = $validator->validate(MonitorInputFixture::http())->data;
    $empty = $validator->validate(['body' => '', 'headers' => []], $current);
    $cleared = $validator->validate(['body' => null], $current);
    expect($empty->data->candidate()['body'])->toBe('')->and($empty->data->candidate()['headers'])->toBe([])
        ->and($empty->patch->values)->toBe(['headers' => [], 'body' => ''])
        ->and($cleared->patch->values)->toBe(['body' => null]);
});

it('revalidates combinations after merge and against a later snapshot', function (): void {
    $validator = app(MonitorInputValidator::class);
    $current = $validator->validate(MonitorInputFixture::http())->data;
    expect(fn () => $validator->validate(['method' => 'GET'], $current))->toThrow(ValidationException::class);
    $patch = $validator->validate(['body' => 'new body'], $current)->patch;
    $later = $validator->validate(['method' => 'GET', 'body' => null], $current)->data;
    expect(fn () => $validator->validate($patch->values, $later))->toThrow(ValidationException::class);
    expect($validator->validate(['method' => 'GET', 'body' => null], $current)->data->candidate()['body'])->toBeNull();
});

it('requires new type configuration on a type change and never inherits old type fields', function (): void {
    $validator = app(MonitorInputValidator::class);
    $current = $validator->validate(MonitorInputFixture::http())->data;
    expect(fn () => $validator->validate(['type' => 'tls'], $current))->toThrow(ValidationException::class);
    $result = $validator->validate(['type' => 'tls', 'tls_host' => 'Example.Invalid.', 'tls_port' => 443], $current);
    expect($result->data->configuration->type())->toBe(MonitorType::Tls)
        ->and($result->data->name)->toBe($current->name)
        ->and($result->data->candidate()['url'])->toBeNull()
        ->and($result->patch->values)->toBe(['type' => 'tls', 'tls_host' => 'example.invalid', 'tls_port' => 443]);
});

it('rejects empty patches including transport metadata alone', function (): void {
    $validator = app(MonitorInputValidator::class);
    $current = $validator->validate(MonitorInputFixture::http())->data;
    expect(fn () => $validator->validate([], $current))->toThrow(ValidationException::class);
    expect(fn () => $validator->validate(['_token' => 'test', '_method' => 'PATCH'], $current))->toThrow(ValidationException::class);
});

it('validates optional TLS and heartbeat ranges without treating empty values as null', function (array $payload): void {
    expect(fn () => app(MonitorInputValidator::class)->validate($payload))->toThrow(ValidationException::class);
})->with([
    [[...MonitorInputFixture::cases()['tls'][0], 'tls_port' => 0]],
    [[...MonitorInputFixture::cases()['tls'][0], 'tls_port' => 65536]],
    [[...MonitorInputFixture::cases()['tls'][0], 'tls_expiry_warning_days' => -1]],
    [[...MonitorInputFixture::cases()['tls'][0], 'tls_expiry_warning_days' => 3651]],
    [[...MonitorInputFixture::cases()['tls'][0], 'tls_expiry_warning_days' => '']],
    [[...MonitorInputFixture::cases()['heartbeat'][0], 'heartbeat_grace_seconds' => -1]],
    [[...MonitorInputFixture::cases()['heartbeat'][0], 'heartbeat_grace_seconds' => 86401]],
    [[...MonitorInputFixture::cases()['dns'][0], 'dns_resolver' => 'resolver.example.invalid']],
    [[...MonitorInputFixture::cases()['dns'][0], 'dns_resolver' => '']],
]);


it('accepts inclusive optional ranges and explicit clearing of nullable configuration', function (): void {
    $validator = app(MonitorInputValidator::class);
    $tls = $validator->validate([...MonitorInputFixture::cases()['tls'][0], 'tls_expiry_warning_days' => 3650])->data;
    expect($tls->candidate()['tls_expiry_warning_days'])->toBe(3650)
        ->and($validator->validate(['tls_expiry_warning_days' => null], $tls)->data->candidate()['tls_expiry_warning_days'])->toBeNull();
    $dns = $validator->validate([...MonitorInputFixture::cases()['dns'][0], 'dns_resolver' => '2001:0db8::53'])->data;
    expect($dns->candidate()['dns_resolver'])->toBe('2001:db8::53')
        ->and($validator->validate(['dns_resolver' => null], $dns)->data->candidate()['dns_resolver'])->toBeNull();
    expect($validator->validate([...MonitorInputFixture::cases()['heartbeat'][0], 'heartbeat_grace_seconds' => 86400])->data->candidate()['heartbeat_grace_seconds'])->toBe(86400);
});
