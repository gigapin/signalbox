<?php

declare(strict_types=1);

/** Verify the literal address policy for every blocked family, embedded IPv4 forms and configured exceptions. */

use App\Services\Security\OutboundAddressPolicy;
use Illuminate\Config\Repository;

/**
 * @param list<mixed> $allowed Raw outbound-security.allowed_cidrs value.
 * @return OutboundAddressPolicy Policy built from an isolated configuration repository.
 */
function addressPolicy(array $allowed = []): OutboundAddressPolicy
{
    return new OutboundAddressPolicy(new Repository(['outbound-security' => ['allowed_cidrs' => $allowed]]));
}

it('blocks private, reserved and special-purpose addresses', function (string $address): void {
    expect(addressPolicy()->allows($address))->toBeFalse();
})->with([
    'this network' => '0.0.0.0',
    'private 10/8' => '10.1.2.3',
    'shared CGNAT' => '100.64.0.1',
    'CGNAT upper bound' => '100.127.255.255',
    'loopback' => '127.0.0.1',
    'loopback range' => '127.255.255.254',
    'link-local metadata' => '169.254.169.254',
    'private 172.16/12 low' => '172.16.0.1',
    'private 172.16/12 high' => '172.31.255.255',
    'IETF assignments' => '192.0.0.8',
    'documentation 1' => '192.0.2.1',
    '6to4 relay anycast' => '192.88.99.1',
    'private 192.168/16' => '192.168.1.1',
    'benchmarking' => '198.19.0.1',
    'documentation 2' => '198.51.100.7',
    'documentation 3' => '203.0.113.9',
    'multicast' => '224.0.0.1',
    'reserved' => '240.0.0.1',
    'broadcast' => '255.255.255.255',
    'IPv6 unspecified' => '::',
    'IPv6 loopback' => '::1',
    'IPv6 unique local' => 'fd00::1',
    'IPv6 link-local' => 'fe80::1',
    'IPv6 site-local' => 'fec0::1',
    'IPv6 multicast' => 'ff02::1',
    'IPv6 discard' => '100::1',
    'IPv6 documentation' => '2001:db8::1',
    'IPv6 documentation 2024' => '3fff::1',
    'Teredo' => '2001:0:4136:e378:8000:63bf:3fff:fdd2',
    'NAT64 with public IPv4' => '64:ff9b::808:808',
    'IPv4-compatible deprecated' => '::127.0.0.1',
    'IPv4-mapped loopback' => '::ffff:127.0.0.1',
    'IPv4-mapped metadata in hex' => '::ffff:a9fe:a9fe',
    '6to4 embedding private IPv4' => '2002:c0a8:0101::1',
    '6to4 embedding loopback' => '2002:7f00:1::',
]);

it('allows public unicast addresses, including mapped and 6to4 forms of public IPv4', function (string $address): void {
    expect(addressPolicy()->allows($address))->toBeTrue();
})->with([
    'public IPv4' => '8.8.8.8',
    'just below 100.64/10' => '100.63.255.255',
    'just above 100.64/10' => '100.128.0.0',
    'just below 172.16/12' => '172.15.255.255',
    'just above 172.16/12' => '172.32.0.0',
    'public IPv6' => '2606:4700:4700::1111',
    'just above 2001::/23' => '2001:200::1',
    'IPv4-mapped public' => '::ffff:8.8.8.8',
    '6to4 embedding public IPv4' => '2002:0808:0808::1',
]);

it('refuses hostnames and legacy numeric address spellings', function (string $value): void {
    expect(addressPolicy()->allows($value))->toBeFalse();
})->with(['example.com', '2130706433', '0177.0.0.1', '127.1', '0x7f.0.0.1', '', ' 8.8.8.8', '8.8.8.8/32']);

it('lets configured CIDR exceptions override the blocklist only inside their range', function (): void {
    $policy = addressPolicy(['127.0.0.1/32', 'fd12:3456::/32', '10.20.0.0']);

    expect($policy->allows('127.0.0.1'))->toBeTrue()
        ->and($policy->allows('127.0.0.2'))->toBeFalse()
        ->and($policy->allows('::ffff:127.0.0.1'))->toBeTrue()
        ->and($policy->allows('fd12:3456::9'))->toBeTrue()
        ->and($policy->allows('fd12:3457::9'))->toBeFalse()
        ->and($policy->allows('10.20.0.0'))->toBeTrue()
        ->and($policy->allows('10.20.0.1'))->toBeFalse();
});

it('rejects malformed CIDR exception configuration at construction', function (mixed $allowed): void {
    expect(fn () => new OutboundAddressPolicy(new Repository(['outbound-security' => ['allowed_cidrs' => $allowed]])))
        ->toThrow(LogicException::class);
})->with([
    'not a list' => [['a' => '10.0.0.0/8']],
    'hostname' => [['localhost']],
    'prefix too long' => [['10.0.0.0/33']],
    'IPv6 prefix too long' => [['fd00::/129']],
    'negative prefix' => [['10.0.0.0/-1']],
    'non-string' => [[10]],
    'scalar' => ['10.0.0.0/8'],
]);

it('reads the default configuration as an empty exception list', function (): void {
    $config = require __DIR__.'/../../config/outbound-security.php';

    expect($config['allowed_cidrs'])->toBe([])
        ->and($config['max_redirects'])->toBe(5);
});
