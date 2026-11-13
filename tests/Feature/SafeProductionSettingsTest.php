<?php

declare(strict_types=1);

/**
 * Check the startup refusal of settings that would silently weaken security outside local development and tests.
 *
 * The rules are exercised on an isolated configuration repository, then through real `artisan` processes started
 * with production environment variables, which proves the check runs while the application boots.
 */

use App\Actions\EnsureSafeProductionSettings;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Process;

/**
 * Settings a correct production deployment would load; each case changes one of them.
 *
 * @param array<string, mixed> $overrides Dot-notation keys replacing the safe values.
 * @return Repository Configuration repository independent of the running test application.
 */
function safeSettings(array $overrides = []): Repository
{
    $config = new Repository([
        'app' => ['debug' => false],
        'session' => ['secure' => true],
        'mail' => ['default' => 'smtp'],
        'status-domains' => ['proxy_ips' => ['10.0.0.5', '2001:db8::5/128']],
        'outbound-security' => ['allowed_cidrs' => []],
        'reverb' => ['apps' => ['apps' => [['allowed_origins' => ['app.signalbox.example']]]]],
    ]);

    foreach ($overrides as $key => $value) {
        $config->set($key, $value);
    }

    return $config;
}

/**
 * Environment variables for a child `artisan` process that would otherwise pass every rule.
 *
 * @param array<string, string> $overrides Variables replacing the safe values.
 * @return array<string, string> Complete production-like environment for the child process.
 */
function safeProcessEnvironment(array $overrides = []): array
{
    return [
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'SESSION_SECURE_COOKIE' => 'true',
        'MAIL_MAILER' => 'smtp',
        'TRUSTED_PROXY_IPS' => '10.0.0.5',
        'OUTBOUND_ALLOWED_CIDRS' => '',
        'REVERB_ALLOWED_ORIGINS' => 'app.signalbox.example',
        ...$overrides,
    ];
}

it('accepts development defaults in local and testing', function (string $environment): void {
    $unsafe = safeSettings([
        'app.debug' => true,
        'session.secure' => null,
        'mail.default' => 'log',
        'status-domains.proxy_ips' => [],
        'outbound-security.allowed_cidrs' => ['10.0.0.0/8'],
        'reverb.apps.apps.0.allowed_origins' => ['localhost', '127.0.0.1'],
    ]);

    expect(app(EnsureSafeProductionSettings::class)->violations($environment, $unsafe))->toBe([]);
})->with(['local', 'testing']);

it('accepts a safe configuration in every other environment', function (string $environment): void {
    app(EnsureSafeProductionSettings::class)($environment, safeSettings());

    expect(app(EnsureSafeProductionSettings::class)->violations($environment, safeSettings()))->toBe([]);
})->with(['production', 'staging', 'demo', 'Local']);

it('refuses each unsafe setting outside development', function (array $overrides, string $message): void {
    expect(fn () => app(EnsureSafeProductionSettings::class)('production', safeSettings($overrides)))
        ->toThrow(LogicException::class, $message);
})->with([
    'debug pages' => [['app.debug' => true], 'APP_DEBUG must be false.'],
    'cookie without Secure' => [['session.secure' => null], 'SESSION_SECURE_COOKIE must be true.'],
    'cookie explicitly insecure' => [['session.secure' => false], 'SESSION_SECURE_COOKIE must be true.'],
    'mail written to the log' => [['mail.default' => 'log'], 'MAIL_MAILER must not be log'],
    'no trusted proxies' => [['status-domains.proxy_ips' => []], 'TRUSTED_PROXY_IPS must list the reverse proxy peers.'],
    'trust every proxy' => [['status-domains.proxy_ips' => ['*']], 'not wildcards or /0 ranges'],
    'trust the socket peer' => [['status-domains.proxy_ips' => ['10.0.0.5', 'REMOTE_ADDR']], 'not wildcards or /0 ranges'],
    'trust private subnets' => [['status-domains.proxy_ips' => ['private_subnets']], 'not wildcards or /0 ranges'],
    'every IPv4 address' => [['status-domains.proxy_ips' => ['0.0.0.0/0']], 'not wildcards or /0 ranges'],
    'every IPv6 address' => [['status-domains.proxy_ips' => ['::/0']], 'not wildcards or /0 ranges'],
    'private network exceptions' => [['outbound-security.allowed_cidrs' => ['10.0.0.0/8']], 'OUTBOUND_ALLOWED_CIDRS must be empty'],
    'loopback WebSocket origin' => [['reverb.apps.apps.0.allowed_origins' => ['app.signalbox.example', '127.0.0.1']], 'REVERB_ALLOWED_ORIGINS must list only production hostnames'],
    'localhost WebSocket origin' => [['reverb.apps.apps.0.allowed_origins' => ['localhost']], 'REVERB_ALLOWED_ORIGINS'],
    'any WebSocket origin' => [['reverb.apps.apps.0.allowed_origins' => ['*']], 'REVERB_ALLOWED_ORIGINS'],
]);

it('reports every violation at once by setting name, never by value', function (): void {
    $config = safeSettings([
        'app.debug' => true,
        'mail.default' => 'log',
        'outbound-security.allowed_cidrs' => ['10.20.30.0/24'],
    ]);

    try {
        app(EnsureSafeProductionSettings::class)('staging', $config);
        $this->fail('Unsafe staging settings must be refused.');
    } catch (LogicException $exception) {
        expect($exception->getMessage())->toStartWith('Unsafe settings for the "staging" environment:')
            ->toContain('APP_DEBUG')
            ->toContain('MAIL_MAILER')
            ->toContain('OUTBOUND_ALLOWED_CIDRS')
            ->not->toContain('10.20.30.0/24');
    }

    expect(app(EnsureSafeProductionSettings::class)->violations('staging', $config))->toHaveCount(3);
});

it('stops a production process at boot while a setting is unsafe and starts it once all are safe', function (): void {
    $refused = Process::path(base_path())
        ->env(safeProcessEnvironment(['SESSION_SECURE_COOKIE' => '', 'MAIL_MAILER' => 'log']))
        ->run(['php', 'artisan', 'list', '--raw']);

    expect($refused->successful())->toBeFalse()
        ->and($refused->output().$refused->errorOutput())
        ->toContain('SESSION_SECURE_COOKIE must be true.')
        ->toContain('MAIL_MAILER must not be log');

    $started = Process::path(base_path())
        ->env(safeProcessEnvironment())
        ->run(['php', 'artisan', 'list', '--raw']);

    expect($started->successful())->toBeTrue($started->errorOutput());
});
