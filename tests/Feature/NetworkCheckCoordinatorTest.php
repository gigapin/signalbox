<?php

declare(strict_types=1);

/** Verify TLS/DNS admission, alias interpretation, safe outcomes and cumulative deadlines without network I/O. */

use App\Contracts\Monitoring\CheckClock;
use App\Contracts\Monitoring\DnsTransport;
use App\Contracts\Monitoring\TlsTransport;
use App\Data\Monitoring\Checks\CheckInput;
use App\Data\Monitoring\DnsMonitorConfiguration;
use App\Data\Monitoring\TlsMonitorConfiguration;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\DnsRecordType;
use App\Enums\Monitoring\MonitorStatus;
use App\Exceptions\CheckTransportFailed;
use App\Exceptions\MissingTenantContext;
use App\Services\Monitoring\Checks\HttpCheckCoordinator;
use App\Services\Monitoring\Checks\NetworkCheckCoordinator;
use App\Services\Security\SystemHostResolver;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Query\Query;
use Tests\Support\CheckInputFixture;
use Tests\Support\FakeCheckClock;
use Tests\Support\FakeDnsTransport;
use Tests\Support\FakeTlsTransport;

beforeEach(function (): void {
    $this->dns = new FakeDnsTransport();
    $this->tls = new FakeTlsTransport();
    $this->clock = new FakeCheckClock();
    app()->instance(DnsTransport::class, $this->dns);
    app()->instance(TlsTransport::class, $this->tls);
    app()->instance(CheckClock::class, $this->clock);
});

/**
 * @param list<CheckInput> $inputs Trusted configurations for synthetic team one.
 * @return array<int, App\Data\Monitoring\Checks\CheckOutcome> Results keyed by execution identity.
 */
function runNetworkChecks(array $inputs): array
{
    return app(TenantContext::class)->run(1, fn (): array => app(NetworkCheckCoordinator::class)->run($inputs));
}

/**
 * @param string $name Query owner without a terminal dot.
 * @param int $type Wire family.
 * @param list<Record> $records Synthetic answer-section records.
 * @param int $rcode DNS response code.
 * @return Message Complete synthetic wire response.
 */
function networkDnsReply(string $name, int $type, array $records, int $rcode = Message::RCODE_OK): Message
{
    $reply = Message::createResponseWithAnswersForQuery(new Query($name.'.', $type, Message::CLASS_IN), $records);
    $reply->rcode = $rcode;

    return $reply;
}

it('starts mixed observations before replies and preserves input correlation and original TLS identity', function (): void {
    $this->dns->answers['tls.example.test.|1'] = networkDnsReply('tls.example.test', 1, [new Record('tls.example.test', 1, 1, 60, '8.8.8.8')]);
    $this->dns->answers['dns.example.test.|1'] = networkDnsReply('dns.example.test', 1, [new Record('dns.example.test', 1, 1, 90, '1.1.1.1')]);
    $this->dns->beforeReply = function (): void {
        expect($this->dns->queries)->toHaveCount(3);
    };

    $results = runNetworkChecks([
        CheckInputFixture::make(9, new TlsMonitorConfiguration('tls.example.test', 8443)),
        CheckInputFixture::make(2, new DnsMonitorConfiguration('dns.example.test', DnsRecordType::A, ['1.1.1.1'])),
    ]);

    expect(array_keys($results))->toBe([9, 2])
        ->and($results[9]->status)->toBe(MonitorStatus::Up)
        ->and($results[9]->tlsExpiresAt)->not->toBeNull()
        ->and($results[2]->status)->toBe(MonitorStatus::Up)
        ->and($this->tls->destinations[0]->host)->toBe('tls.example.test')
        ->and($this->tls->destinations[0]->port)->toBe(8443)
        ->and($this->tls->destinations[0]->addresses)->toBe(['8.8.8.8']);
});

it('validates the complete batch before DNS or TLS I/O', function (string $case): void {
    $tls = new TlsMonitorConfiguration('tls.example.test', 443);
    $inputs = match ($case) {
        'duplicate' => [CheckInputFixture::make(configuration: $tls), CheckInputFixture::make(configuration: $tls)],
        'foreign' => [CheckInputFixture::make(configuration: $tls), CheckInputFixture::make(2, $tls, teamId: 2)],
        'unsupported' => [CheckInputFixture::make(configuration: $tls), CheckInputFixture::make(2)],
        'timeout-small' => [CheckInputFixture::make(configuration: $tls, timeoutMs: 99)],
        'timeout-large' => [CheckInputFixture::make(configuration: $tls, timeoutMs: 10001)],
        'size' => array_map(fn (int $id) => CheckInputFixture::make($id, $tls), range(1, 11)),
        'not-list' => [2 => CheckInputFixture::make(configuration: $tls)],
        'invalid-dns' => [CheckInputFixture::make(configuration: $tls), CheckInputFixture::make(2, new DnsMonitorConfiguration('dns.example.test', DnsRecordType::A, ['invalid']))],
    };

    expect(fn () => runNetworkChecks($inputs))->toThrow(InvalidArgumentException::class)
        ->and($this->dns->queries)->toBeEmpty()
        ->and($this->tls->destinations)->toBeEmpty();
})->with(['duplicate', 'foreign', 'unsupported', 'timeout-small', 'timeout-large', 'size', 'not-list', 'invalid-dns']);

it('requires tenant context and restores it after empty batches or failures', function (): void {
    expect(fn () => app(NetworkCheckCoordinator::class)->run([CheckInputFixture::make(configuration: new TlsMonitorConfiguration('8.8.8.8', 443))]))->toThrow(MissingTenantContext::class)
        ->and(runNetworkChecks([]))->toBe([])
        ->and(fn () => app(TenantContext::class)->requireTeamId())->toThrow(MissingTenantContext::class);
});

it('rejects forbidden TLS and custom resolver destinations before I/O', function (string $case): void {
    $configuration = match ($case) {
        'local' => new TlsMonitorConfiguration('localhost', 443),
        'private' => new TlsMonitorConfiguration('127.0.0.1', 443),
        'resolver' => new DnsMonitorConfiguration('dns.example.test', DnsRecordType::A, ['8.8.8.8'], '127.0.0.1'),
    };
    $result = runNetworkChecks([CheckInputFixture::make(configuration: $configuration)])[1];

    expect($result->error)->toBe($case === 'local' ? CheckError::LocalName : CheckError::BlockedAddress)
        ->and($result->latencyMs)->toBeNull()
        ->and($this->dns->queries)->toBeEmpty()
        ->and($this->tls->destinations)->toBeEmpty();
})->with(['local', 'private', 'resolver']);

it('refuses mixed public and private TLS answers without trying any connection', function (): void {
    $this->dns->answers['tls.example.test.|1'] = networkDnsReply('tls.example.test', 1, [
        new Record('tls.example.test', 1, 1, 60, '8.8.8.8'),
        new Record('tls.example.test', 1, 1, 60, '127.0.0.1'),
    ]);
    $result = runNetworkChecks([CheckInputFixture::make(configuration: new TlsMonitorConfiguration('tls.example.test', 443))])[1];

    expect($result->error)->toBe(CheckError::BlockedAddress)
        ->and($this->tls->destinations)->toBeEmpty();
});

it('uses the guarded custom resolver but treats returned private record addresses as comparison data', function (): void {
    $this->dns->answers['dns.example.test.|1'] = networkDnsReply('dns.example.test', 1, [new Record('dns.example.test', 1, 1, 0, '127.0.0.1')]);
    $result = runNetworkChecks([CheckInputFixture::make(configuration: new DnsMonitorConfiguration('dns.example.test', DnsRecordType::A, ['127.0.0.1'], '8.8.8.8'))])[1];

    expect($result->status)->toBe(MonitorStatus::Up)
        ->and($this->dns->queries[0]['server'])->toBe('8.8.8.8:53')
        ->and($this->tls->destinations)->toBeEmpty();
});

it('evaluates all supported DNS wire payloads and ignores unrelated answer records', function (DnsRecordType $type, int $wireType, mixed $payload, string $expected): void {
    $this->dns->answers['dns.example.test.|'.$wireType] = networkDnsReply('dns.example.test', $wireType, [
        new Record('unrelated.example.test', $wireType, 1, 60, 'ignored'),
        new Record('DNS.EXAMPLE.TEST.', $wireType, 1, 300, $payload),
    ]);
    $result = runNetworkChecks([CheckInputFixture::make(configuration: new DnsMonitorConfiguration('dns.example.test', $type, [$expected]))])[1];

    expect($result->status)->toBe(MonitorStatus::Up);
})->with([
    [DnsRecordType::A, 1, '8.8.8.8', '8.8.8.8'],
    [DnsRecordType::AAAA, 28, '2001:4860:4860:0:0:0:0:8888', '2001:4860:4860::8888'],
    [DnsRecordType::CNAME, 5, 'TARGET.EXAMPLE.TEST.', 'target.example.test'],
    [DnsRecordType::MX, 15, ['priority' => 10, 'target' => 'MAIL.EXAMPLE.TEST.'], '10 mail.example.test'],
    [DnsRecordType::MX, 15, ['priority' => 0, 'target' => ''], '0 .'],
    [DnsRecordType::TXT, 16, [' a', "\tB "], " a\tB "],
]);

it('follows inline or separately queried aliases under a shared deadline', function (bool $inline): void {
    $records = [new Record('dns.example.test', 5, 1, 60, 'target.example.test')];
    $answer = new Record('target.example.test', 1, 1, 60, '8.8.8.8');
    $this->dns->answers['dns.example.test.|1'] = networkDnsReply('dns.example.test', 1, $inline ? [...$records, $answer] : $records);
    $this->dns->answers['target.example.test.|1'] = networkDnsReply('target.example.test', 1, [$answer]);
    $this->dns->beforeReply = function (): void {
        $this->clock->now += 10;
    };
    $result = runNetworkChecks([CheckInputFixture::make(configuration: new DnsMonitorConfiguration('dns.example.test', DnsRecordType::A, ['8.8.8.8']))])[1];

    expect($result->status)->toBe(MonitorStatus::Up)
        ->and($this->dns->queries)->toHaveCount($inline ? 1 : 2);

    if (!$inline) {
        expect($this->dns->queries[1]['timeout'])->toBe(990.0);
    }
})->with([true, false]);

it('classifies DNS negative, server and malformed responses safely', function (string $case, CheckError $expected): void {
    $records = match ($case) {
        'bad-address' => [new Record('dns.example.test', 1, 1, 60, 'secret-invalid-payload')],
        'cycle' => [new Record('dns.example.test', 5, 1, 60, 'dns.example.test')],
        'ambiguous' => [new Record('dns.example.test', 5, 1, 60, 'a.example.test'), new Record('dns.example.test', 5, 1, 60, 'b.example.test')],
        default => [],
    };
    $rcode = match ($case) {
        'nxdomain' => Message::RCODE_NAME_ERROR,
        'servfail' => Message::RCODE_SERVER_FAILURE,
        default => Message::RCODE_OK,
    };
    $this->dns->answers['dns.example.test.|1'] = networkDnsReply('dns.example.test', 1, $records, $rcode);
    $result = runNetworkChecks([CheckInputFixture::make(configuration: new DnsMonitorConfiguration('dns.example.test', DnsRecordType::A, ['8.8.8.8']))])[1];

    expect($result->error)->toBe($expected)
        ->and($result->errorMessage())->not->toContain('secret', 'dns.example.test');
})->with([
    ['empty', CheckError::DnsMissing], ['nxdomain', CheckError::DnsMissing], ['servfail', CheckError::DnsResponse],
    ['bad-address', CheckError::DnsMalformed], ['cycle', CheckError::DnsMalformed], ['ambiguous', CheckError::DnsMalformed],
]);

it('limits alias chains to eight hops', function (): void {
    foreach (range(0, 8) as $index) {
        $name = 'alias'.$index.'.example.test';
        $this->dns->answers[$name.'.|1'] = networkDnsReply($name, 1, [new Record($name, 5, 1, 60, 'alias'.($index + 1).'.example.test')]);
    }
    $result = runNetworkChecks([CheckInputFixture::make(configuration: new DnsMonitorConfiguration('alias0.example.test', DnsRecordType::A, ['8.8.8.8']))])[1];
    expect($result->error)->toBe(CheckError::DnsMalformed)
        ->and($this->dns->queries)->toHaveCount(9);
});

it('rejects completion exactly at the DNS deadline without retry', function (): void {
    $this->dns->beforeReply = function (): void {
        $this->clock->now = 1000;
    };
    $result = runNetworkChecks([CheckInputFixture::make(configuration: new DnsMonitorConfiguration('dns.example.test', DnsRecordType::A, ['8.8.8.8']))])[1];
    expect($result->error)->toBe(CheckError::Timeout)
        ->and($this->dns->queries)->toHaveCount(1);
});

it('subtracts DNS acquisition from the TLS handshake budget', function (): void {
    $this->dns->answers['tls.example.test.|1'] = networkDnsReply('tls.example.test', 1, [new Record('tls.example.test', 1, 1, 60, '8.8.8.8')]);
    $this->dns->beforeReply = function (): void {
        $this->clock->now += 10;
    };
    $result = runNetworkChecks([CheckInputFixture::make(configuration: new TlsMonitorConfiguration('tls.example.test', 443))])[1];
    expect($result->status)->toBe(MonitorStatus::Up)
        ->and($result->latencyMs)->toBe(20.0)
        ->and($this->tls->budgets)->toBe([980.0]);
});

it('turns recognized TLS failures into safe outcomes and propagates programming errors', function (): void {
    $input = CheckInputFixture::make(configuration: new TlsMonitorConfiguration('8.8.8.8', 443));
    $this->tls->failure = new CheckTransportFailed(CheckError::Tls);
    expect(runNetworkChecks([$input])[1]->error)->toBe(CheckError::Tls);
    $this->tls->failure = new LogicException('Synthetic setup error.');
    expect(fn () => runNetworkChecks([$input]))->toThrow(LogicException::class);
});

it('resolves A and AAAA together for the HTTP guard and refuses partial data after a family failure', function (): void {
    $this->dns->answers['http.example.test.|1'] = networkDnsReply('http.example.test', 1, [new Record('http.example.test', 1, 1, 60, '8.8.8.8')]);
    expect(app(SystemHostResolver::class)->resolve('http.example.test'))->toBe(['8.8.8.8']);
    $this->dns->answers['http.example.test.|28'] = new CheckTransportFailed(CheckError::DnsResponse);
    expect(app(SystemHostResolver::class)->resolve('http.example.test'))->toBe([]);
    $this->dns->answers['http.example.test.|28'] = new CheckTransportFailed(CheckError::Timeout);
    expect(fn () => app(SystemHostResolver::class)->resolve('http.example.test'))->toThrow(CheckTransportFailed::class);
});

it('returns input order even when a later TLS observation completes before an earlier DNS observation', function (): void {
    $results = runNetworkChecks([
        CheckInputFixture::make(9, new DnsMonitorConfiguration('dns.example.test', DnsRecordType::A, ['8.8.8.8'])),
        CheckInputFixture::make(1, new TlsMonitorConfiguration('8.8.8.8', 443)),
    ]);

    expect(array_keys($results))->toBe([9, 1])
        ->and($results[9]->error)->toBe(CheckError::DnsMissing)
        ->and($results[1]->status)->toBe(MonitorStatus::Up);
});

it('propagates programming errors and cancels unrelated pending acquisitions', function (): void {
    $this->tls->failure = new LogicException('Synthetic setup error.');

    expect(fn () => runNetworkChecks([
        CheckInputFixture::make(1, new DnsMonitorConfiguration('dns.example.test', DnsRecordType::A, ['8.8.8.8'])),
        CheckInputFixture::make(2, new TlsMonitorConfiguration('8.8.8.8', 443)),
    ]))->toThrow(LogicException::class)
        ->and($this->dns->cancelled)->toBe(1);
});

it('does not follow a direct CNAME monitor target', function (): void {
    $this->dns->answers['dns.example.test.|5'] = networkDnsReply('dns.example.test', 5, [new Record('dns.example.test', 5, 1, 60, 'target.example.test')]);
    $result = runNetworkChecks([CheckInputFixture::make(configuration: new DnsMonitorConfiguration('dns.example.test', DnsRecordType::CNAME, ['target.example.test']))])[1];
    expect($result->status)->toBe(MonitorStatus::Up)
        ->and($this->dns->queries)->toHaveCount(1);
});

it('rejects malformed DNS data and mismatched questions', function (string $case): void {
    $type = $case === 'txt' ? DnsRecordType::TXT : DnsRecordType::MX;
    $wire = $type === DnsRecordType::TXT ? 16 : 15;
    $payload = match ($case) {
        'txt' => ['good', 1],
        'mx' => ['priority' => 'bad', 'target' => 'mail.example.test'],
        default => ['priority' => 10, 'target' => 'mail.example.test'],
    };
    $reply = networkDnsReply($case === 'question' ? 'other.example.test' : 'dns.example.test', $wire, [new Record('dns.example.test', $wire, 1, 60, $payload)]);
    $this->dns->answers['dns.example.test.|'.$wire] = $reply;
    $result = runNetworkChecks([CheckInputFixture::make(configuration: new DnsMonitorConfiguration('dns.example.test', $type, [$type === DnsRecordType::TXT ? 'good' : '10 mail.example.test']))])[1];
    expect($result->error)->toBe(CheckError::DnsMalformed);
})->with(['txt', 'mx', 'question']);

it('maps the HTTP guard DNS deadline to one safe timeout without sending a request', function (): void {
    Http::fake();
    $this->dns->answers['checker.example.test.|1'] = new CheckTransportFailed(CheckError::Timeout);
    $result = app(TenantContext::class)->run(1, fn (): array => app(HttpCheckCoordinator::class)->run([CheckInputFixture::make()]))[1];
    expect($result->error)->toBe(CheckError::Timeout)
        ->and($result->latencyMs)->toBeNull();
    Http::assertNothingSent();
    expect($this->dns->queries)->toHaveCount(2);
});

it('refuses malformed DNS and batch configuration instead of removing operational bounds', function (): void {
    config(['monitor-checks.resolution_timeout_ms' => 0]);
    expect(fn () => app(SystemHostResolver::class)->resolve('checker.example.test'))->toThrow(LogicException::class);
    config(['monitor-checks.max_batch_size' => null]);
    expect(fn () => runNetworkChecks([]))->toThrow(LogicException::class)
        ->and($this->dns->queries)->toBeEmpty();
});
