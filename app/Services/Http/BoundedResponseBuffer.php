<?php

declare(strict_types=1);

/** Own the bounded response sink shared by monitoring checks and notification HTTP attempts. */

namespace App\Services\Http;

use RuntimeException;

/** The PHP filter enforces the same cap for real HTTP and Http::fake without overriding cURL callbacks. */
final class BoundedResponseBuffer
{
    /** @var string Process-local filter name; registration is reused by long-lived workers. */
    private const string FILTER = 'signalbox.http-response-limit';

    /** @var resource Temporary stream containing at most the remaining decoded allowance. */
    private $stream;

    /**
     * @param ResponseByteBudget $budget Decoded-byte allowance for one request or a sequence of redirect hops.
     * @throws RuntimeException When the temporary stream or its enforcing filter cannot be created.
     */
    public function __construct(ResponseByteBudget $budget)
    {
        if (!in_array(self::FILTER, stream_get_filters(), true)
            && !stream_filter_register(self::FILTER, ResponseLimitFilter::class)) {
            throw new RuntimeException('Cannot register the response limit filter.');
        }

        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new RuntimeException('Cannot open the response buffer.');
        }

        if (stream_filter_append($stream, self::FILTER, STREAM_FILTER_WRITE, $budget) === false) {
            fclose($stream);

            throw new RuntimeException('Cannot attach the response limit filter.');
        }

        $this->stream = $stream;
    }

    /** @return resource Writable bounded resource accepted by both the real and fake Laravel HTTP handlers. */
    public function sink()
    {
        return $this->stream;
    }

    /**
     * @return string Complete decoded bytes for this hop; callers reject observations with an exceeded budget.
     * @throws RuntimeException When a readable live buffer could not be rewound or read.
     */
    public function contents(): string
    {
        if (!rewind($this->stream)) {
            throw new RuntimeException('Cannot rewind the response buffer.');
        }

        $contents = stream_get_contents($this->stream);

        if ($contents === false) {
            throw new RuntimeException('Cannot read the response buffer.');
        }

        return $contents;
    }

    /** Close a live stream exactly once, including during exception cleanup. */
    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    /** Release an unclosed buffer when orchestration unwinds before explicit cleanup. */
    public function __destruct()
    {
        $this->close();
    }
}
