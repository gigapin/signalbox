<?php

declare(strict_types=1);

/** Exercise formatted database exceptions through real Laravel channels and the emergency fallback. */

use App\Logging\RedactingLogManager;
use Illuminate\Database\QueryException;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;

it('removes database values from formatted records including nested chains and extra fields', function (bool $json): void {
    $stream = fopen('php://memory', 'w+');
    $handler = new \Monolog\Handler\StreamHandler($stream);
    $handler->setFormatter($json ? new JsonFormatter() : new LineFormatter(null, null, true, true));
    $logger = new \Monolog\Logger('test', [$handler], [new \App\Logging\SecretRedactionProcessor(new \App\Services\Security\SecretRedactor())]);
    $driver = new PDOException('duplicate key email=private@example.test hash=private-hash ciphertext=private-ciphertext', 23505);
    $query = new QueryException('private-connection', 'insert into private_table values (?, ?, ?)', ['private@example.test', 'private-hash', 'private-ciphertext'], $driver);
    $wrapper = new RuntimeException($query->getMessage(), 0, $query);
    $record = new \Monolog\LogRecord(
        new DateTimeImmutable(),
        'test',
        \Monolog\Level::Error,
        (string) $wrapper,
        ['nested' => ['exception' => $wrapper, 'duplicate' => $query->getMessage()], 'sql' => $query->getSql(), 'bindings' => $query->getBindings(), 'numeric_bindings' => [987654321], 'monitor_id' => 42],
        ['nested' => [$query], 'duplicate' => (string) $driver],
    );
    $logger->addRecord($record->level, $record->message, $record->context);
    // Exercise extra independently because addRecord builds its own extra fields.
    $handler->handle((new \App\Logging\SecretRedactionProcessor(new \App\Services\Security\SecretRedactor()))($record));
    rewind($stream);
    $output = stream_get_contents($stream);

    foreach (['private@example.test', 'private-hash', 'private-ciphertext', 'private_table', 'private-connection', 'insert into', 'duplicate key', '987654321'] as $secret) {
        expect($output)->not->toContain($secret);
    }

    expect($output)->toContain('Database operation failed.', '23505', 'QueryException', 'PDOException', 'RuntimeException', '42')
        ->and($query->getBindings())->toBe(['private@example.test', 'private-hash', 'private-ciphertext']);
    fclose($stream);
})->with(['line' => false, 'json' => true]);

it('protects ordinary channels and the actual emergency fallback before its first write', function (bool $fallback): void {
    $path = tempnam(sys_get_temp_dir(), 'signalbox-log-');

    try {
        config()->set('logging.channels.emergency.path', $path);
        config()->set('logging.channels.sec-test', [
            'driver' => 'single',
            'path' => $path,
            'tap' => [\App\Logging\RedactSecrets::class],
            'replace_placeholders' => true,
        ]);
        $manager = app('log');
        expect($manager)->toBeInstanceOf(RedactingLogManager::class)
            ->and(app(LogManager::class))->toBe($manager);

        if ($fallback) {
            $manager->extend('sec-failure', static function (): never {
                throw new QueryException('pgsql', 'select private_sql', [], new PDOException('private-driver-detail', 23505));
            });
            config()->set('logging.channels.sec-test.driver', 'sec-failure');
        }

        $logger = Log::channel('sec-test');
        $exception = new QueryException('pgsql', 'select ?', ['private-binding'], new PDOException('private-driver-detail', 23505));
        $logger->error($exception);
        $logger->warning('ordinary {authorization}', ['authorization' => 'private-authorization', 'monitor_id' => 42]);
        $output = file_get_contents($path);

        expect($output)->toContain('23505', 'Database operation failed.', '42')
            ->not->toContain('private-binding', 'private-driver-detail', 'private_sql', 'private-authorization');

        if ($fallback) {
            expect(substr_count($output, 'Database operation failed.'))->toBeGreaterThanOrEqual(2);
        }
    } finally {
        Log::forgetChannel('sec-test');
        unlink($path);
    }
})->with(['single' => false, 'fallback' => true]);

it('retains ordinary exception diagnostics without exposing trace arguments', function (): void {
    $handler = new \Monolog\Handler\TestHandler();
    $logger = new \Monolog\Logger('test', [$handler], [new \App\Logging\SecretRedactionProcessor(new \App\Services\Security\SecretRedactor())]);
    $exception = new RuntimeException('Timeout https://example.test/?token=private-token');
    $logger->error('Probe failed', ['exception' => $exception, 'monitor_id' => 42]);
    $context = $handler->getRecords()[0]->context;

    expect($context['exception'])->toMatchArray([
        'class' => RuntimeException::class,
        'message' => 'Timeout https://example.test/?token=[REDACTED]',
        'file' => __FILE__,
    ])->and($context['monitor_id'])->toBe(42);
});

it('redacts framework exception reporting and shared context in a stack channel', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'signalbox-report-log-');
    config()->set('logging.channels.sec-report', [
        'driver' => 'single',
        'path' => $path,
        'tap' => [\App\Logging\RedactSecrets::class],
    ]);
    config()->set('logging.channels.sec-stack', [
        'driver' => 'stack',
        'channels' => ['sec-report'],
    ]);
    config()->set('logging.default', 'sec-stack');

    try {
        $exception = new QueryException('pgsql', 'select ?', ['private-report-binding'], new PDOException('private-report-driver', 23505));
        \Illuminate\Support\Facades\Context::add('nested', ['exception' => $exception, 'copy' => $exception->getMessage()]);
        report(new RuntimeException('private-report-wrapper', 0, $exception));
        $output = file_get_contents($path);

        expect($output)->toContain('23505', 'Database operation failed.', 'RuntimeException', 'QueryException')
            ->not->toContain('private-report-binding', 'private-report-driver', 'private-report-wrapper', 'select');
    } finally {
        \Illuminate\Support\Facades\Context::flush();
        Log::forgetChannel('sec-stack');
        Log::forgetChannel('sec-report');
        unlink($path);
    }
});
