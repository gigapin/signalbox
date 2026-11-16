<?php

declare(strict_types=1);

/** Derive independent protocol keys and verify persisted digests through an explicit rotation keyring. */

namespace App\Services\Security;

use App\Enums\Security\KeyPurpose;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Encryption\Encrypter;
use LogicException;

/** No secret is cached: configuration changes are visible when constructing a fresh consumer/encrypter. */
final readonly class PurposeKeys
{
    /** @param Repository $config Current/previous application keys and the legacy compatibility switch. */
    public function __construct(private Repository $config)
    {
    }

    /**
     * @param KeyPurpose $purpose Closed protocol label.
     * @return list<string> Current derived key first, previous derived keys, then optional legacy encodings.
     */
    public function ring(KeyPurpose $purpose): array
    {
        $roots = $this->roots();
        $keys = array_map(fn (string $root): string => $this->derive($root, $purpose), $roots);
        if ($this->config->get('cryptography.allow_legacy', true) === true) {
            foreach ($roots as $root) {
                $keys[] = $purpose === KeyPurpose::Encryption ? $this->decode($root) : $root;
            }
        }

        return array_values(array_unique($keys));
    }

    /** @return Encrypter Official Laravel cipher with derived write key and bounded historical read keys. */
    public function encrypter(): Encrypter
    {
        $keys = $this->ring(KeyPurpose::Encryption);

        return (new Encrypter($keys[0], (string) $this->config->get('app.cipher', 'AES-256-CBC')))
            ->previousKeys(array_slice($keys, 1));
    }

    /**
     * @param KeyPurpose $purpose Digest protocol.
     * @param string $message Canonical domain input, never logged.
     * @return string Version, non-secret root identifier and hexadecimal HMAC; at most 85 ASCII bytes.
     */
    public function digest(KeyPurpose $purpose, #[\SensitiveParameter] string $message): string
    {
        return $this->forRoot($this->roots()[0], $purpose, $message);
    }

    /**
     * @param KeyPurpose $purpose Digest protocol.
     * @param string $message Current canonical input.
     * @param string|null $legacyMessage Original canonical input; null retains the same message.
     * @return list<string> Accepted current, previous and optional original-format digests for SQL lookups.
     */
    public function candidates(KeyPurpose $purpose, #[\SensitiveParameter] string $message, #[\SensitiveParameter] ?string $legacyMessage = null): array
    {
        $roots = $this->roots();
        $values = array_map(fn (string $root): string => $this->forRoot($root, $purpose, $message), $roots);
        if ($this->config->get('cryptography.allow_legacy', true) === true) {
            foreach ($roots as $root) {
                $values[] = hash_hmac('sha256', $legacyMessage ?? $message, $root);
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param string $stored Opaque database/session digest, including legacy values during upgrade.
     * @param KeyPurpose $purpose Expected purpose; another purpose never authorizes a new-format value.
     * @param string $message Current canonical input.
     * @param string|null $legacyMessage Original input, when different.
     * @return bool Whether an explicitly configured key authenticates this value for this purpose.
     */
    public function matches(string $stored, KeyPurpose $purpose, #[\SensitiveParameter] string $message, #[\SensitiveParameter] ?string $legacyMessage = null): bool
    {
        foreach ($this->candidates($purpose, $message, $legacyMessage) as $candidate) {
            if (hash_equals($candidate, $stored)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $stored Saved digest or an already retired, non-authorizing marker.
     * @return bool Whether migration can interpret this key generation; never authenticates the message itself.
     */
    public function knowsDigestKey(string $stored): bool
    {
        if (str_starts_with($stored, 'retired:')) {
            return true;
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $stored) === 1) {
            return $this->config->get('cryptography.allow_legacy', true) === true;
        }
        if (preg_match('/^sb1:([a-f0-9]{16}):[a-f0-9]{64}$/D', $stored, $match) !== 1) {
            return false;
        }
        foreach ($this->roots() as $root) {
            if (hash_equals(substr(hash('sha256', $this->decode($root)), 0, 16), $match[1])) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> Validated, distinct configured roots, without falling back on empty or weak secrets. */
    private function roots(): array
    {
        $current = $this->config->get('app.key');
        $previous = $this->config->get('app.previous_keys', []);
        if (!is_string($current) || !is_array($previous)) {
            throw new LogicException('Invalid cryptographic key configuration.');
        }

        $roots = [$current];
        foreach ($previous as $root) {
            if (!is_string($root)) {
                throw new LogicException('Invalid cryptographic key configuration.');
            }
            $roots[] = $root;
        }
        foreach ($roots as $root) {
            $this->decode($root);
        }

        return array_values(array_unique($roots));
    }

    /**
     * @param string $root Laravel's raw or base64-encoded root; never included in diagnostics.
     * @return string Exactly 32 secret bytes for this project's AES-256 baseline.
     * @throws LogicException Configuration is empty, malformed or has an unsupported size.
     */
    private function decode(#[\SensitiveParameter] string $root): string
    {
        $bytes = str_starts_with($root, 'base64:') ? base64_decode(substr($root, 7), true) : $root;
        if (!is_string($bytes) || strlen($bytes) !== 32) {
            throw new LogicException('Invalid cryptographic key configuration.');
        }

        return $bytes;
    }

    /**
     * @param string $root Validated root, decoded before HKDF extraction.
     * @param KeyPurpose $purpose Stable closed protocol label, never supplied by an untrusted caller.
     * @return string Independent 32-byte subkey; the public salt and label are stable protocol constants.
     */
    private function derive(#[\SensitiveParameter] string $root, KeyPurpose $purpose): string
    {
        return hash_hkdf('sha256', $this->decode($root), 32, 'signalbox:v1:'.$purpose->value, 'signalbox:key-derivation:v1');
    }

    /**
     * @param string $root Configured root whose public identifier is embedded in the envelope.
     * @param KeyPurpose $purpose Expected cryptographic protocol.
     * @param string $message Canonical domain input, never logged.
     * @return string Versioned digest whose public key identifier selects no additional verification authority.
     */
    private function forRoot(#[\SensitiveParameter] string $root, KeyPurpose $purpose, #[\SensitiveParameter] string $message): string
    {
        $id = substr(hash('sha256', $this->decode($root)), 0, 16);

        return 'sb1:'.$id.':'.hash_hmac('sha256', $message, $this->derive($root, $purpose));
    }
}
