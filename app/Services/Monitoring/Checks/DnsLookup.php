<?php

declare(strict_types=1);

/** Interpret bounded DNS answers without consulting hosts files, search domains or application caches. */

namespace App\Services\Monitoring\Checks;

use App\Contracts\Monitoring\CheckClock;
use App\Contracts\Monitoring\DnsTransport;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\DnsRecordType;
use App\Exceptions\CheckTransportFailed;
use App\Services\Monitoring\MonitorInputSyntax;
use InvalidArgumentException;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

use function React\Promise\reject;

/** Only answer-section records belonging to the requested name or its verified alias chain are considered. */
final readonly class DnsLookup
{
    /**
     * @param DnsTransport $transport Replaceable, cancellable DNS wire adapter.
     * @param MonitorInputSyntax $syntax Shared canonical IP/name/MX normalization.
     * @param CheckClock $clock Monotonic clock shared across every query in an observation.
     */
    public function __construct(private DnsTransport $transport, private MonitorInputSyntax $syntax, private CheckClock $clock)
    {
    }

    /**
     * @param string $name Normalized absolute query name without its terminal dot.
     * @param DnsRecordType $type Requested record family.
     * @param string $server Authorized or trusted literal nameserver endpoint.
     * @param float $deadlineMs Absolute monotonic deadline, shared across aliases and transport fallback.
     * @param LoopInterface $loop Batch-owned loop.
     * @param list<string> $visited Already traversed names, used for cycle detection and an eight-alias limit.
     * @return PromiseInterface<list<string>> Canonical complete record values, possibly empty.
     */
    public function records(string $name, DnsRecordType $type, string $server, float $deadlineMs, LoopInterface $loop, array $visited = []): PromiseInterface
    {
        if (in_array($name, $visited, true) || count($visited) > 8) {
            return reject(new CheckTransportFailed(CheckError::DnsMalformed));
        }

        if ($deadlineMs <= $this->clock->milliseconds()) {
            return reject(new CheckTransportFailed(CheckError::Timeout));
        }

        $wireType = constant(Message::class.'::TYPE_'.$type->value);

        return $this->transport->query($name.'.', $wireType, $server, $deadlineMs - $this->clock->milliseconds(), $loop)
            ->then(function (Message $response) use ($name, $type, $server, $deadlineMs, $loop, $visited): array|PromiseInterface {
                if ($deadlineMs <= $this->clock->milliseconds()) {
                    throw new CheckTransportFailed(CheckError::Timeout);
                }

                $question = $response->questions[0] ?? null;

                if (!$response->qr || $response->tc || count($response->questions) !== 1 || $question === null
                    || strtolower(rtrim($question->name, '.')) !== $name || $question->type !== constant(Message::class.'::TYPE_'.$type->value)
                    || $question->class !== Message::CLASS_IN) {
                    throw new CheckTransportFailed(CheckError::DnsMalformed);
                }

                if ($response->rcode === Message::RCODE_NAME_ERROR) {
                    return [];
                }

                if ($response->rcode !== Message::RCODE_OK) {
                    throw new CheckTransportFailed(CheckError::DnsResponse);
                }

                return $this->answers($response, $name, $type, $server, $deadlineMs, $loop, $visited);
            });
    }

    /**
     * @param Message $response Parsed DNS response containing untrusted record data.
     * @param string $name Name whose records are currently being evaluated.
     * @param DnsRecordType $type Requested family.
     * @param string $server Selected nameserver endpoint.
     * @param float $deadlineMs Cumulative monotonic deadline.
     * @param LoopInterface $loop Batch-owned event loop.
     * @param list<string> $visited Traversed aliases.
     * @return list<string>|PromiseInterface<list<string>> Complete canonical values or an alias query.
     * @throws CheckTransportFailed On ambiguous, malformed or cyclic answers.
     */
    private function answers(Message $response, string $name, DnsRecordType $type, string $server, float $deadlineMs, LoopInterface $loop, array $visited): array|PromiseInterface
    {
        $values = [];
        $aliases = [];
        $wireType = constant(Message::class.'::TYPE_'.$type->value);

        try {
            foreach ($response->answers as $record) {
                if ($record->class !== Message::CLASS_IN || strtolower(rtrim($record->name, '.')) !== $name) {
                    continue;
                }

                if ($record->type === $wireType) {
                    $values[] = $this->value($record, $type);
                }

                if ($record->type === Message::TYPE_CNAME) {
                    $aliases[] = $this->value($record, DnsRecordType::CNAME);
                }
            }
        } catch (InvalidArgumentException) {
            throw new CheckTransportFailed(CheckError::DnsMalformed);
        }

        $aliases = array_values(array_unique($aliases));

        if (count($aliases) > 1 || ($type !== DnsRecordType::CNAME && $values !== [] && $aliases !== [])) {
            throw new CheckTransportFailed(CheckError::DnsMalformed);
        }

        if ($values !== [] || $aliases === [] || $type === DnsRecordType::CNAME) {
            return array_values(array_unique($values));
        }

        $target = $aliases[0];
        $visited[] = $name;

        if (in_array($target, $visited, true) || count($visited) > 8) {
            throw new CheckTransportFailed(CheckError::DnsMalformed);
        }

        foreach ($response->answers as $record) {
            if (strtolower(rtrim($record->name, '.')) === $target && $record->class === Message::CLASS_IN
                && ($record->type === $wireType || $record->type === Message::TYPE_CNAME)) {
                return $this->answers($response, $target, $type, $server, $deadlineMs, $loop, $visited);
            }
        }

        return $this->records($target, $type, $server, $deadlineMs, $loop, $visited);
    }

    /**
     * @param Record $record Untrusted parsed record payload.
     * @param DnsRecordType $type Known record family.
     * @return string Canonical comparison value, preserving exact concatenated TXT bytes.
     * @throws InvalidArgumentException When the payload has an invalid shape or value.
     */
    private function value(Record $record, DnsRecordType $type): string
    {
        return $this->normalize($record->data, $type);
    }

    /**
     * @param mixed $data Untrusted wire payload; vendor annotations do not enforce its runtime shape.
     * @param DnsRecordType $type Known record family.
     * @return string Canonical comparison value.
     * @throws InvalidArgumentException When the payload shape or value is invalid.
     */
    private function normalize(mixed $data, DnsRecordType $type): string
    {

        if ($type === DnsRecordType::TXT) {
            if (!is_array($data) || !array_is_list($data) || $data === []) {
                throw new InvalidArgumentException('Invalid DNS text record.');
            }

            foreach ($data as $part) {
                if (!is_string($part)) {
                    throw new InvalidArgumentException('Invalid DNS text segment.');
                }
            }

            return implode('', $data);
        }

        if ($type === DnsRecordType::MX) {
            if (!is_array($data) || !isset($data['priority'], $data['target']) || !is_int($data['priority']) || !is_string($data['target'])) {
                throw new InvalidArgumentException('Invalid DNS mail record.');
            }

            $data = $data['priority'].' '.($data['target'] === '' ? '.' : rtrim($data['target'], '.'));
        }

        if (!is_string($data)) {
            throw new InvalidArgumentException('Invalid DNS record payload.');
        }

        return match ($type) {
            DnsRecordType::A => $this->syntax->ip($data, FILTER_FLAG_IPV4),
            DnsRecordType::AAAA => $this->syntax->ip($data, FILTER_FLAG_IPV6),
            DnsRecordType::CNAME => $this->syntax->host(rtrim($data, '.'), allowIp: false),
            default => $this->syntax->dnsValues(DnsRecordType::MX, [$data])[0],
        };
    }
}
