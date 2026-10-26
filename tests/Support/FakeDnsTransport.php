<?php

declare(strict_types=1);

/** Supply deterministic DNS wire responses without network access or wall-clock waits. */

namespace Tests\Support;

use App\Contracts\Monitoring\DnsTransport;
use Closure;
use React\Dns\Model\Message;
use React\Dns\Query\Query;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;

/** Future-tick completion allows the coordinator to start every observation before any response arrives. */
final class FakeDnsTransport implements DnsTransport
{
    /** @var array<string, Message|Throwable> Responses keyed by absolute query name and numeric type. */
    public array $answers = [];
    /** @var list<array{name: string, type: int, server: string, timeout: float}> Recorded query attempts. */
    public array $queries = [];
    /** @var Closure(string, int): void|null Optional controlled-clock hook before resolving a response. */
    public ?Closure $beforeReply = null;
    /** @var int Number of pending queries cancelled by the owning batch. */
    public int $cancelled = 0;

    /**
     * @param string $name Absolute query name.
     * @param int $type Wire family.
     * @param string $server Selected resolver endpoint.
     * @param float $timeoutMs Remaining budget recorded for assertions.
     * @param LoopInterface $loop Local test loop.
     * @return PromiseInterface<Message> Cancellable deterministic response.
     */
    public function query(string $name, int $type, string $server, float $timeoutMs, LoopInterface $loop): PromiseInterface
    {
        $this->queries[] = ['name' => $name, 'type' => $type, 'server' => $server, 'timeout' => $timeoutMs];
        $cancelled = false;
        $deferred = new Deferred(function () use (&$cancelled): void {
            $cancelled = true;
            $this->cancelled++;
            throw new RuntimeException('Synthetic query cancelled.');
        });
        $loop->futureTick(function () use ($name, $type, $deferred, &$cancelled): void {
            if ($cancelled) {
                return;
            }

            if ($this->beforeReply !== null) {
                ($this->beforeReply)($name, $type);
            }

            $answer = $this->answers[$name.'|'.$type] ?? Message::createResponseWithAnswersForQuery(new Query($name, $type, Message::CLASS_IN), []);

            if ($answer instanceof Throwable) {
                $deferred->reject($answer);
            } else {
                $deferred->resolve($answer);
            }
        });

        return $deferred->promise();
    }
}
