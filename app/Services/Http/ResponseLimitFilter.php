<?php

declare(strict_types=1);

/** Bound actual sink writes, including decoded cURL output and Laravel fake response writes. */

namespace App\Services\Http;

use LogicException;
use php_user_filter;

/** Return a fatal stream-write result at overflow so the transport aborts instead of truncating silently. */
final class ResponseLimitFilter extends php_user_filter
{
    /**
     * @param resource $in Incoming decoded stream buckets.
     * @param resource $out Buckets accepted for persistence into the bounded temporary stream.
     * @param int $consumed Bytes accepted by this filter invocation.
     * @param bool $closing Whether the stream is being flushed for closure.
     * @return int PHP stream-filter status; PSFS_ERR_FATAL aborts the oversized transfer.
     * @throws LogicException When the sink was configured without its shared response budget.
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        if (!$this->params instanceof ResponseByteBudget) {
            throw new LogicException('The response stream requires a byte budget.');
        }

        while ($bucket = stream_bucket_make_writeable($in)) {
            if (!$this->params->accept($bucket->datalen)) {
                return PSFS_ERR_FATAL;
            }

            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
