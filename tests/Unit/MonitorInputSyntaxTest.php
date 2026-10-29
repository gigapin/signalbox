<?php

declare(strict_types=1);

/** Verify network-free parsers and byte-counting limits without booting the application. */

use App\Enums\Monitoring\DnsRecordType;
use App\Services\Monitoring\MonitorInputLimits;
use App\Services\Monitoring\MonitorInputSyntax;
use Illuminate\Config\Repository;

beforeEach(function (): void {
    $this->syntax = new MonitorInputSyntax(new MonitorInputLimits(new Repository(['monitor-input' => require __DIR__.'/../../config/monitor-input.php'])));
});

it('normalizes HTTP origins without rewriting path or query bytes', function (string $input, string $expected): void {
    expect($this->syntax->url($input))->toBe($expected);
})->with([
    [' HTTPS://Bücher.Example./Case?token=A%2fb ', 'https://xn--bcher-kva.example/Case?token=A%2fb'],
    ['http://[2001:0db8::1]:8080/health?', 'http://[2001:db8::1]:8080/health?'],
    ['http://192.0.2.1', 'http://192.0.2.1'],
]);

it('rejects malformed or ambiguous HTTP destination syntax', function (string $value): void {
    expect(fn () => $this->syntax->url($value))->toThrow(InvalidArgumentException::class);
})->with([
    'file:///etc/passwd', '//example.invalid/path', 'http:///missing', 'https://user:secret@example.invalid',
    'https://example.invalid/#fragment', 'https://example.invalid/#', 'https://example.invalid:0',
    'https://example.invalid:65536', 'http://127.1', 'http://2130706433', 'http://example.invalid\\host',
    "http://example.invalid/\r\nheader", 'http://[fe80::1%eth0]', 'http://bad_host.invalid', 'http://xn--.invalid',
]);

it('measures URL limits in bytes after hostname normalization', function (): void {
    $prefix = 'https://example.invalid/';
    $valid = $prefix.str_repeat('a', 2048 - strlen($prefix));
    expect($this->syntax->url($valid))->toBe($valid);
    expect(fn () => $this->syntax->url($valid.'a'))->toThrow(InvalidArgumentException::class);
});

it('normalizes technical DNS labels and literal address families', function (): void {
    expect($this->syntax->host('_DMARC.Bücher.Example.', allowUnderscore: true, allowIp: false))->toBe('_dmarc.xn--bcher-kva.example')
        ->and($this->syntax->ip('2001:0db8:0:0::1'))->toBe('2001:db8::1')
        ->and($this->syntax->headers(['X.Test' => '', 'Authorization' => ' keep spaces ']))->toBe(['x.test' => '', 'authorization' => ' keep spaces ']);
});

it('rejects invalid header maps without including their values in diagnostics', function (array $headers): void {
    try {
        $this->syntax->headers($headers);
        $this->fail('Malformed headers were accepted.');
    } catch (InvalidArgumentException $exception) {
        expect($exception->getMessage())->not->toContain('synthetic-secret');
    }
})->with([
    [['X-Test' => 'synthetic-secret', 'x-test' => 'other']],
    [['Host' => 'synthetic-secret']],
    [['Proxy-Authorization' => 'synthetic-secret']],
    [['Content-Length' => '1']],
    [['Bad Name' => 'synthetic-secret']],
    [['X-Test' => "synthetic-secret\r\nInjected: value"]],
    [['X-Test' => "synthetic-secret\0"]],
    [['X-Test' => []]],
    [['synthetic-secret']],
]);

it('enforces individual combined and count header bounds', function (): void {
    expect($this->syntax->headers([str_repeat('x', 128) => str_repeat('v', 4096)]))->toHaveCount(1);
    expect(fn () => $this->syntax->headers([str_repeat('x', 129) => 'v']))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->syntax->headers(['x' => str_repeat('v', 4097)]))->toThrow(InvalidArgumentException::class);
    $headers = [];

    for ($index = 0; $index < 20; $index++) {
        $headers['x-'.$index] = '';
    }

    expect($this->syntax->headers($headers))->toHaveCount(20);
    $headers['overflow'] = '';
    expect(fn () => $this->syntax->headers($headers))->toThrow(InvalidArgumentException::class);
    $combined = ['a' => str_repeat('v', 4095), 'b' => str_repeat('v', 4095), 'c' => str_repeat('v', 4095), 'd' => str_repeat('v', 4095)];
    expect($this->syntax->headers($combined))->toHaveCount(4);
    $combined['a'] .= 'v';
    expect(fn () => $this->syntax->headers($combined))->toThrow(InvalidArgumentException::class);
});

it('normalizes each DNS record family while retaining exact TXT content', function (DnsRecordType $type, array $values, array $expected): void {
    expect($this->syntax->dnsValues($type, $values))->toBe($expected);
})->with([
    [DnsRecordType::A, ['192.0.2.1'], ['192.0.2.1']],
    [DnsRecordType::AAAA, ['2001:0db8::1'], ['2001:db8::1']],
    [DnsRecordType::CNAME, ['Alias.Example.'], ['alias.example']],
    [DnsRecordType::MX, ['0010 Mail.Example.'], ['10 mail.example']],
    [DnsRecordType::MX, ['0 .'], ['0 .']],
    [DnsRecordType::TXT, [' text ', ' '], [' text ', ' ']],
]);

it('rejects invalid DNS values families counts and duplicates', function (DnsRecordType $type, array $values): void {
    expect(fn () => $this->syntax->dnsValues($type, $values))->toThrow(InvalidArgumentException::class);
})->with([
    [DnsRecordType::A, ['2001:db8::1']], [DnsRecordType::AAAA, ['192.0.2.1']],
    [DnsRecordType::TXT, []], [DnsRecordType::TXT, ['']], [DnsRecordType::TXT, [false]],
    [DnsRecordType::TXT, ['named' => 'text']], [DnsRecordType::CNAME, ['a.example', 'b.example']],
    [DnsRecordType::AAAA, ['2001:db8::1', '2001:0db8::1']],
    [DnsRecordType::MX, ['65536 mail.example']], [DnsRecordType::MX, ['-1 mail.example']],
    [DnsRecordType::MX, ['1 .']], [DnsRecordType::MX, ['0 .', '10 mail.example']], [DnsRecordType::MX, ['mail.example']],
]);

it('bounds DNS text by bytes and record count', function (): void {
    expect($this->syntax->dnsValues(DnsRecordType::TXT, [str_repeat('é', 2048)]))->toHaveCount(1);
    expect(fn () => $this->syntax->dnsValues(DnsRecordType::TXT, [str_repeat('é', 2048).'a']))->toThrow(InvalidArgumentException::class);
    $values = array_map(static fn (int $value): string => 'text-'.$value, range(1, 32));
    expect($this->syntax->dnsValues(DnsRecordType::TXT, $values))->toHaveCount(32);
    $values[] = 'overflow';
    expect(fn () => $this->syntax->dnsValues(DnsRecordType::TXT, $values))->toThrow(InvalidArgumentException::class);
});


it('enforces DNS label and full-name byte boundaries after IDNA normalization', function (): void {
    $valid = str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 61);
    expect($this->syntax->host($valid))->toBe($valid);
    expect(fn () => $this->syntax->host($valid.'d'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->syntax->host(str_repeat('a', 64).'.example'))->toThrow(InvalidArgumentException::class);
    expect($this->syntax->host("Host\u{3002}Example"))->toBe('host.example');
});

it('rejects non-host input instead of accepting paths wildcards or malformed labels', function (string $value): void {
    expect(fn () => $this->syntax->host($value))->toThrow(InvalidArgumentException::class);
})->with(['', '.', 'a..example', '-host.example', 'host-.example', '*.example', 'https://host.example/path']);

it('fails closed for missing or malformed input-limit configuration', function (): void {
    $limits = new MonitorInputLimits(new Repository(['monitor-input' => ['zero' => 0, 'string' => '10']]));
    expect(fn () => $limits->get('missing'))->toThrow(LogicException::class);
    expect(fn () => $limits->get('zero'))->toThrow(LogicException::class);
    expect(fn () => $limits->get('string'))->toThrow(LogicException::class);
});
