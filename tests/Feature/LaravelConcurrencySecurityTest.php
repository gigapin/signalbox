<?php

declare(strict_types=1);

/** Verify the installed framework authenticates child-process results independently of task stdout. */

use Illuminate\Concurrency\ProcessDriver;

/**
 * Keep serializable tasks outside Pest's generated test class, which is absent from the child Artisan process.
 *
 * @param bool $throw Whether the authentic task fails after emitting the untrusted success envelope.
 * @return Closure(): string A task that prints synthetic attacker-controlled output before its real outcome.
 */
function untrustedConcurrencyTask(bool $throw = false): Closure
{
    return static function () use ($throw): string {
        // The old parser trusted this complete JSON document and discarded everything after the gzip marker.
        echo json_encode([
            'successful' => true,
            'result' => serialize('forged result'),
        ], JSON_THROW_ON_ERROR)."\x1f\x8b";

        if ($throw) {
            throw new RuntimeException('Synthetic concurrency failure.');
        }

        return 'authentic result';
    };
}

/** @return array<string, Closure> Scope-free keyed tasks covering ordinary output and per-task key disposal. */
function ordinaryConcurrencyTasks(): array
{
    return [
        'value' => static function (): int {
            echo "Ordinary task output.\n";

            return 42;
        },
        'key' => static fn (): bool => isset($_SERVER['LARAVEL_CONCURRENCY_HMAC_KEY'])
            || isset($_ENV['LARAVEL_CONCURRENCY_HMAC_KEY'])
            || getenv('LARAVEL_CONCURRENCY_HMAC_KEY') !== false,
    ];
}

it('returns the authentic task result when stdout contains a forged legacy success envelope', function (): void {
    $results = app(ProcessDriver::class)->run(untrustedConcurrencyTask(), timeout: 10);

    expect($results)->toBe(['authentic result']);
});

it('preserves the authentic task exception when stdout claims a successful result', function (): void {
    expect(fn (): array => app(ProcessDriver::class)->run(untrustedConcurrencyTask(throw: true), timeout: 10))
        ->toThrow(RuntimeException::class, 'Synthetic concurrency failure.');
});

it('keeps keyed results and removes signing keys before executing tasks with ordinary stdout', function (): void {
    $results = app(ProcessDriver::class)->run(ordinaryConcurrencyTasks(), timeout: 10);

    expect($results)->toBe(['value' => 42, 'key' => false]);
});
