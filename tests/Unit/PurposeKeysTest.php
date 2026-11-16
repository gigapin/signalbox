<?php

declare(strict_types=1);

/** Verify independent HKDF protocols, strict root decoding and explicitly bounded historical verification. */

use App\Enums\Security\KeyPurpose;
use App\Services\Security\PurposeKeys;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;

/** @return Repository Synthetic public configuration; no deployment key is read by these tests. */
function purposeKeyConfiguration(): Repository
{
    return new Repository([
        'app' => ['key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'previous_keys' => [], 'cipher' => 'AES-256-CBC'],
        'cryptography' => ['allow_legacy' => false],
    ]);
}

it('derives a distinct key and digest for every closed purpose', function (): void {
    $keys = new PurposeKeys(purposeKeyConfiguration());
    $derived = [];
    $digests = [];
    foreach (KeyPurpose::cases() as $purpose) {
        $derived[] = $keys->ring($purpose)[0];
        $digests[] = $keys->digest($purpose, 'same canonical input');
        expect(strlen($derived[array_key_last($derived)]))->toBe(32)
            ->and($digests[array_key_last($digests)])->toMatch('/^sb1:[a-f0-9]{16}:[a-f0-9]{64}$/');
    }
    expect(array_unique($derived))->toHaveCount(count(KeyPurpose::cases()))
        ->and(array_unique($digests))->toHaveCount(count(KeyPurpose::cases()));
    foreach (KeyPurpose::cases() as $purpose) {
        expect($keys->matches($keys->digest(KeyPurpose::SubscriberConsent, 'x'), $purpose, 'x'))->toBe($purpose === KeyPurpose::SubscriberConsent);
    }
});

it('preserves previous derived reads during rotation and rejects them after key retirement', function (): void {
    $config = purposeKeyConfiguration();
    $keys = new PurposeKeys($config);
    $oldDigest = $keys->digest(KeyPurpose::SubscriberEmail, '1:reader@example.test');
    $ciphertext = $keys->encrypter()->encryptString('synthetic private value');
    $oldRoot = $config->get('app.key');
    $config->set('app.key', 'base64:'.base64_encode(str_repeat('b', 32)));
    $config->set('app.previous_keys', [$oldRoot]);
    expect($keys->matches($oldDigest, KeyPurpose::SubscriberEmail, '1:reader@example.test'))->toBeTrue()
        ->and($keys->knowsDigestKey($oldDigest))->toBeTrue()
        ->and($keys->matches($oldDigest, KeyPurpose::SubscriberEmail, '2:reader@example.test'))->toBeFalse()
        ->and($keys->encrypter()->decryptString($ciphertext))->toBe('synthetic private value')
        ->and($keys->digest(KeyPurpose::SubscriberEmail, '1:reader@example.test'))->not->toBe($oldDigest);
    $config->set('app.previous_keys', []);
    expect($keys->matches($oldDigest, KeyPurpose::SubscriberEmail, '1:reader@example.test'))->toBeFalse()
        ->and($keys->knowsDigestKey($oldDigest))->toBeFalse()
        ->and(fn () => $keys->encrypter()->decryptString($ciphertext))->toThrow(DecryptException::class);
});

it('accepts original encodings only through the explicit legacy switch', function (): void {
    $config = purposeKeyConfiguration();
    $keys = new PurposeKeys($config);
    $legacy = hash_hmac('sha256', 'old canonical input', $config->get('app.key'));
    $ciphertext = (new Encrypter(str_repeat('a', 32), 'AES-256-CBC'))->encryptString('legacy private value');
    expect($keys->matches($legacy, KeyPurpose::SubscriberConsent, 'new canonical input', 'old canonical input'))->toBeFalse();
    $config->set('cryptography.allow_legacy', true);
    expect($keys->matches($legacy, KeyPurpose::SubscriberConsent, 'new canonical input', 'old canonical input'))->toBeTrue()
        ->and($keys->encrypter()->decryptString($ciphertext))->toBe('legacy private value');
    $config->set('cryptography.allow_legacy', false);
    expect(fn () => $keys->encrypter()->decryptString($ciphertext))->toThrow(DecryptException::class);
});

it('decodes equivalent raw and base64 roots consistently without exposing secrets', function (): void {
    $config = purposeKeyConfiguration();
    $keys = new PurposeKeys($config);
    $before = $keys->digest(KeyPurpose::PageSession, 'proof');
    expect($before)->toBe('sb1:3ba3f5f43b926026:37d2924981485060824c9d499e6799f5842fb203e80a0f8b0d82d0e869fea801');
    $config->set('app.key', str_repeat('a', 32));
    expect($keys->digest(KeyPurpose::PageSession, 'proof'))->toBe($before)
        ->and($keys->ring(KeyPurpose::Encryption))->toHaveCount(1);
});

it('rejects malformed roots without retaining their values in exception diagnostics', function (mixed $root): void {
    $config = purposeKeyConfiguration();
    $config->set('app.key', $root);
    try {
        (new PurposeKeys($config))->digest(KeyPurpose::PageSession, 'input');
        $this->fail('Invalid root configuration must fail closed.');
    } catch (LogicException $error) {
        expect($error->getMessage())->toBe('Invalid cryptographic key configuration.')
            ->and($error->getPrevious())->toBeNull();
    }
})->with([null, '', 'short', 'base64:***', 'base64:'.base64_encode(str_repeat('x', 31)), ['not a string']]);
