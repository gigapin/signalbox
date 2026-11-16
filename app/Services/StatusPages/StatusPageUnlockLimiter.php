<?php

declare(strict_types=1);

/** Coordinate password checks across visitors and application instances using bounded Redis state. */

namespace App\Services\StatusPages;

use App\Exceptions\StatusPageUnlockRateLimited;
use App\Exceptions\StatusPageUnlockUnavailable;
use App\Models\StatusPage;
use Illuminate\Contracts\Redis\Factory;
use Throwable;

/** One leased verification per page/password version, followed by atomic fenced completion. */
final readonly class StatusPageUnlockLimiter
{
    /**
     * Redis supplies the clock; each invocation touches one key and executes in constant time.
     * Five failures start a 1/2/4/8/16/32/60-second backoff, capped at 60 seconds.
     * An abandoned lease counts as failure; active lease/cooldown refusals never extend state or retention.
     * State expires after one hour of inactivity. No plaintext or visitor identity is stored.
     */
    private const string SCRIPT = <<<'LUA'
        local key = KEYS[1]
        local operation = ARGV[1]
        local token = ARGV[2]
        local time = redis.call('TIME')
        local now = tonumber(time[1]) * 1000 + math.floor(tonumber(time[2]) / 1000)
        local failures = tonumber(redis.call('HGET', key, 'failures') or '0')
        local blocked = tonumber(redis.call('HGET', key, 'blocked_until_ms') or '0')
        local lease = tonumber(redis.call('HGET', key, 'lease_until_ms') or '0')
        local owner = redis.call('HGET', key, 'owner')

        if not failures or failures < 0 or failures > 11 or not blocked or not lease then
            return {-1, 1}
        end

        local function fail()
            failures = math.min(failures + 1, 11)
            local delay = 0
            if failures >= 5 then
                delay = math.min(2 ^ (failures - 5), 60)
            end
            redis.call('HSET', key, 'failures', failures, 'blocked_until_ms', now + delay * 1000)
            redis.call('HDEL', key, 'owner', 'lease_until_ms')
            redis.call('EXPIRE', key, 3600)
            return delay
        end

        if operation == 'reserve' then
            if blocked > now then
                return {0, math.ceil((blocked - now) / 1000)}
            end
            if owner then
                if lease > now then
                    return {0, math.max(1, math.ceil((lease - now) / 1000))}
                end
                local delay = fail()
                if delay > 0 then
                    return {0, delay}
                end
            end
            redis.call('HSET', key, 'owner', token, 'lease_until_ms', now + 10000)
            redis.call('EXPIRE', key, 3600)
            return {1, 0}
        end

        if owner ~= token or lease <= now then
            return {0, 1}
        end
        if ARGV[3] == '1' then
            redis.call('DEL', key)
            return {1, 0}
        end
        return {1, fail()}
        LUA;

    /** @param Factory $redis Shared Redis factory; deliberately independent of the default cache store. */
    public function __construct(private Factory $redis)
    {
    }

    /**
     * @param StatusPage $page Protected page; slug and host changes preserve its counter.
     * @return string Opaque ownership token, valid for at most ten seconds.
     * @throws StatusPageUnlockRateLimited Another verification or cooldown is active.
     * @throws StatusPageUnlockUnavailable Shared state could not be read atomically.
     */
    public function reserve(StatusPage $page): string
    {
        $token = bin2hex(random_bytes(32));
        $this->evaluate($page, 'reserve', $token, false);

        return $token;
    }

    /**
     * @param StatusPage $page Same page and hash used for admission.
     * @param string $token Ownership received from reserve().
     * @param bool $matched Whether the submitted password matched the saved hash.
     * @throws StatusPageUnlockRateLimited The lease expired or another attempt now owns the state.
     * @throws StatusPageUnlockUnavailable Completion could not be recorded; access must not be granted.
     */
    public function finish(StatusPage $page, string $token, bool $matched): void
    {
        $this->evaluate($page, 'finish', $token, $matched);
    }

    /**
     * @param StatusPage $page Page identity and opaque password version.
     * @param 'reserve'|'finish' $operation Atomic state transition.
     * @param string $token Verification owner.
     * @param bool $matched Verification result, ignored during admission.
     * @throws StatusPageUnlockRateLimited Admission or completion was refused.
     * @throws StatusPageUnlockUnavailable Backend failed or returned malformed state.
     */
    private function evaluate(StatusPage $page, string $operation, string $token, bool $matched): void
    {
        $key = 'status-page-unlock:'.$page->id.':'.hash('sha256', (string) $page->password_hash);

        try {
            $result = $this->redis->connection('cache')->command('eval', [
                self::SCRIPT, 1, $key, $operation, $token, $matched ? '1' : '0',
            ]);
        } catch (Throwable) {
            throw new StatusPageUnlockUnavailable();
        }

        if (!is_array($result) || count($result) !== 2
            || !isset($result[0], $result[1]) || !is_int($result[0]) || !is_int($result[1])
            || !in_array($result[0], [0, 1], true) || $result[1] < 0 || $result[1] > 60) {
            throw new StatusPageUnlockUnavailable();
        }

        if ($result[0] === 0) {
            throw new StatusPageUnlockRateLimited(max(1, $result[1]));
        }
    }
}
