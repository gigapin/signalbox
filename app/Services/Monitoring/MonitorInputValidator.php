<?php

declare(strict_types=1);

/** Validate explicit monitor patches against optional current state without persistence or network I/O. */

namespace App\Services\Monitoring;

use App\Data\Monitoring\DnsMonitorConfiguration;
use App\Data\Monitoring\HeartbeatMonitorConfiguration;
use App\Data\Monitoring\HttpMonitorConfiguration;
use App\Data\Monitoring\KeywordMonitorConfiguration;
use App\Data\Monitoring\MonitorInput;
use App\Data\Monitoring\MonitorInputPatch;
use App\Data\Monitoring\MonitorRegions;
use App\Data\Monitoring\TlsMonitorConfiguration;
use App\Data\Monitoring\ValidatedMonitorInput;
use App\Enums\Monitoring\DnsRecordType;
use App\Enums\Monitoring\HttpMethod;
use App\Enums\Monitoring\KeywordMode;
use App\Enums\Monitoring\MonitorType;
use App\Exceptions\OutboundDestinationRejected;
use App\Services\Security\OutboundDestinationGuard;
use Closure;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/** Revalidate the explicit patch against a locked fresh snapshot in future mutation actions. */
final readonly class MonitorInputValidator
{
    /** @var list<string> Shared editable fields, independent of configuration variant. */
    private const array COMMON = ['name', 'type', 'interval_seconds', 'timeout_ms', 'confirmation_threshold', 'regions'];

    /** @var array<string, list<string>> Complete public configuration keys per supported type. */
    private const array FIELDS = [
        'http' => ['url', 'method', 'headers', 'body', 'expected_status', 'follow_redirects'],
        'keyword' => ['url', 'method', 'headers', 'body', 'expected_status', 'follow_redirects', 'expected_keyword', 'keyword_mode'],
        'tls' => ['tls_host', 'tls_port', 'tls_expiry_warning_days'],
        'dns' => ['dns_name', 'dns_record_type', 'dns_expected_values', 'dns_resolver'],
        'heartbeat' => ['heartbeat_grace_seconds'],
    ];

    /**
     * @param Factory $factory Laravel validation factory, independent of HTTP requests.
     * @param MonitorInputSyntax $syntax Network-free syntax normalization.
     * @param MonitorInputLimits $limits Configured byte and operational limits.
     * @param OutboundDestinationGuard $guard Outbound policy, used here only for its network-free checks.
     */
    public function __construct(
        private Factory $factory,
        private MonitorInputSyntax $syntax,
        private MonitorInputLimits $limits,
        private OutboundDestinationGuard $guard,
    ) {
    }

    /**
     * @param array<string, mixed> $submitted Explicit caller fields, not an entire model serialization.
     * @param MonitorInput|null $current Authorized current snapshot; null denotes creation.
     * @return ValidatedMonitorInput Complete candidate plus only the normalized explicit patch.
     * @throws ValidationException When input, patch shape or effective merged configuration is invalid.
     */
    public function validate(array $submitted, ?MonitorInput $current = null): ValidatedMonitorInput
    {
        unset($submitted['_token'], $submitted['_method']);

        if ($current !== null && $submitted === []) {
            throw ValidationException::withMessages(['monitor' => 'Submit at least one editable field.']);
        }

        $type = $submitted['type'] ?? $current?->configuration->type()->value;

        if (is_string($type)) {
            $type = strtolower(trim($type));
        }

        if (
            !is_string($type)
            || MonitorType::tryFrom($type) === null
            || (array_key_exists('type', $submitted) && $submitted['type'] === null)
        ) {
            throw ValidationException::withMessages(['type' => 'Select a supported monitor type.']);
        }

        $allowed = [...self::COMMON, ...self::FIELDS[$type]];
        $errors = [];

        foreach ($submitted as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                $errors[$key] = 'This field is not accepted for the selected monitor type.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $existing = $current?->candidate() ?? [];
        $base = array_intersect_key($existing, array_flip(self::COMMON));

        if ($current !== null && $current->configuration->type()->value === $type) {
            $base = array_intersect_key($existing, array_flip($allowed));
        }

        $candidate = [...$this->defaults($type), ...$base, ...$submitted, 'type' => $type];

        foreach (['name', 'url', 'tls_host', 'dns_name', 'dns_resolver'] as $field) {
            if (isset($candidate[$field]) && is_string($candidate[$field])) {
                $candidate[$field] = trim($candidate[$field]);
            }
        }

        foreach (['method', 'dns_record_type'] as $field) {
            if (isset($candidate[$field]) && is_string($candidate[$field])) {
                $candidate[$field] = strtoupper(trim($candidate[$field]));
            }
        }

        $this->factory->make($candidate, $this->rules($type))->validate();
        $candidate = $this->normalize($candidate, $type);
        $data = $this->toInput($candidate, MonitorType::from($type));
        $patch = array_intersect_key($candidate, $submitted);

        return new ValidatedMonitorInput($data, new MonitorInputPatch($patch));
    }

    /**
     * @param string $type Validated monitor type.
     * @return array<string, mixed> Defaults applied only to new configurations or genuinely absent creation fields.
     */
    private function defaults(string $type): array
    {
        $defaults = ['confirmation_threshold' => 3, 'regions' => ['eu']];

        return match ($type) {
            'http', 'keyword' => [
                ...$defaults,
                'method' => 'GET',
                'headers' => [],
                'body' => null,
                'expected_status' => 200,
                'follow_redirects' => false,
                ...($type === 'keyword' ? ['keyword_mode' => 'present'] : []),
            ],
            'tls' => [...$defaults, 'tls_expiry_warning_days' => null],
            'dns' => [...$defaults, 'dns_resolver' => null],
            default => $defaults,
        };
    }

    /**
     * @param string $type Validated variant.
     * @return array<string, list<mixed>> Scalar and collection shape rules; semantic parsing follows successful validation.
     */
    private function rules(string $type): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'interval_seconds' => $this->integerRules($this->limits->get('interval_min_seconds'), $this->limits->get('interval_max_seconds')),
            'timeout_ms' => $this->integerRules($this->limits->get('timeout_min_ms'), $this->limits->get('timeout_max_ms')),
            'confirmation_threshold' => $this->integerRules(1, $this->limits->get('threshold_max')),
            'regions' => ['required', 'array', 'list', 'min:1', 'max:2'],
            'regions.*' => ['required', 'string', 'in:eu,us', 'distinct:strict'],
        ];

        return [...$rules, ...match ($type) {
            'http', 'keyword' => [
                'url' => ['required', 'string'],
                'method' => ['required', 'string', 'in:GET,HEAD,POST,PUT,PATCH,DELETE,OPTIONS'],
                'headers' => ['present', 'array'],
                'body' => ['nullable', 'string'],
                'expected_status' => $this->integerRules(100, 599),
                'follow_redirects' => ['required', 'boolean'],
                ...($type === 'keyword' ? ['expected_keyword' => ['present', 'string'], 'keyword_mode' => ['required', 'string', 'in:present,absent']] : []),
            ],
            'tls' => [
                'tls_host' => ['required', 'string'],
                'tls_port' => $this->integerRules(1, 65535),
                'tls_expiry_warning_days' => $this->integerRules(0, $this->limits->get('tls_warning_max_days'), nullable: true),
            ],
            'dns' => [
                'dns_name' => ['required', 'string'],
                'dns_record_type' => ['required', 'string', 'in:A,AAAA,CNAME,MX,TXT'],
                'dns_expected_values' => ['required', 'array', 'list'],
                'dns_resolver' => ['nullable', 'string'],
            ],
            'heartbeat' => ['heartbeat_grace_seconds' => $this->integerRules(0, $this->limits->get('heartbeat_grace_max_seconds'))],
            default => [],
        }];
    }

    /**
     * @param int $minimum Inclusive lower bound.
     * @param int $maximum Inclusive upper bound.
     * @param bool $nullable Whether explicit null clears an optional setting.
     * @return list<mixed> Decimal-only integer rules accepting HTML decimal strings as well as JSON integers.
     */
    private function integerRules(int $minimum, int $maximum, bool $nullable = false): array
    {
        return [
            'bail', $nullable ? 'nullable' : 'required',
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
                    $fail('The field must be a decimal integer.');
                }
            },
            'integer', 'between:'.$minimum.','.$maximum,
        ];
    }

    /**
     * @param array<string, mixed> $candidate Candidate that passed primitive shape rules.
     * @param string $type Validated variant.
     * @return array<string, mixed> Normalized candidate with strictly typed primitive values.
     * @throws ValidationException When a semantic parser rejects a field; submitted values are never echoed.
     */
    private function normalize(array $candidate, string $type): array
    {
        $normalizers = [
            'regions' => fn () => MonitorRegions::fromValues($this->array($candidate, 'regions'))->toValues(),
        ];

        if ($type === 'http' || $type === 'keyword') {
            $normalizers['url'] = fn () => $this->syntax->url($this->string($candidate, 'url'));
            $normalizers['headers'] = fn () => $this->syntax->headers($this->array($candidate, 'headers'));
            $normalizers['body'] = fn () => $this->boundedText($candidate['body'] ?? null, 'body_max_bytes', nullable: true);

            if ($type === 'keyword') {
                $normalizers['expected_keyword'] = fn () => $this->boundedText($candidate['expected_keyword'] ?? null, 'keyword_max_bytes', nonempty: true);
            }
        } elseif ($type === 'tls') {
            $normalizers['tls_host'] = fn () => $this->syntax->host($this->string($candidate, 'tls_host'));
        } elseif ($type === 'dns') {
            $normalizers['dns_name'] = fn () => $this->syntax->host($this->string($candidate, 'dns_name'), allowUnderscore: true, allowIp: false);
            $normalizers['dns_expected_values'] = fn () => $this->syntax->dnsValues(
                DnsRecordType::from($this->string($candidate, 'dns_record_type')),
                $this->array($candidate, 'dns_expected_values'),
            );
            $normalizers['dns_resolver'] = fn () => $candidate['dns_resolver'] === null ? null : $this->syntax->ip($this->string($candidate, 'dns_resolver'));
        }

        $errors = [];

        // Laravel skips non-implicit rules for empty strings; nullable integers still require decimal syntax when present.
        foreach (['tls_expiry_warning_days'] as $field) {
            if (
                isset($candidate[$field])
                && !is_int($candidate[$field])
                && (!is_string($candidate[$field]) || preg_match('/^[0-9]+$/D', $candidate[$field]) !== 1)
            ) {
                $errors[$field] = 'The field must be a decimal integer or null.';
            }
        }

        foreach ($normalizers as $field => $normalize) {
            try {
                $candidate[$field] = $normalize();
            } catch (InvalidArgumentException $exception) {
                $errors[$field] = $exception->getMessage();
            }
        }

        foreach ($this->destinationHosts($candidate, $type) as $field => $host) {
            if (isset($errors[$field])) {
                continue;
            }

            try {
                $this->guard->assertLocallyAllowed($host);
            } catch (OutboundDestinationRejected $exception) {
                $errors[$field] = $exception->getMessage();
            }
        }

        $body = $candidate['body'] ?? null;

        if (
            in_array($type, ['http', 'keyword'], true)
            && in_array($candidate['method'], ['GET', 'HEAD'], true)
            && $body !== null
            && $body !== ''
        ) {
            $errors['body'] = 'GET and HEAD monitors cannot send a nonempty body.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        foreach (['interval_seconds', 'timeout_ms', 'confirmation_threshold', 'expected_status', 'tls_port', 'tls_expiry_warning_days', 'heartbeat_grace_seconds'] as $field) {
            if (isset($candidate[$field])) {
                $candidate[$field] = (int) $candidate[$field];
            }
        }

        if (array_key_exists('follow_redirects', $candidate)) {
            $candidate['follow_redirects'] = (bool) $candidate['follow_redirects'];
        }

        return $candidate;
    }

    /**
     * Select outbound hosts for the save-time check. Hostnames are not resolved here: the access-time guard
     * repeats the complete policy, including DNS answers, immediately before every connection.
     *
     * @param array<string, mixed> $candidate Candidate after semantic normalization.
     * @param string $type Validated variant.
     * @return array<string, string> Field name mapped to its normalized host or literal address.
     */
    private function destinationHosts(array $candidate, string $type): array
    {
        $hosts = [];
        $url = $candidate['url'] ?? null;

        if (($type === 'http' || $type === 'keyword') && is_string($url)) {
            $host = parse_url($url, PHP_URL_HOST);

            if (is_string($host)) {
                $hosts['url'] = trim($host, '[]');
            }
        }

        foreach (['tls' => 'tls_host', 'dns' => 'dns_resolver'] as $variant => $field) {
            if ($type === $variant && is_string($candidate[$field] ?? null)) {
                $hosts[$field] = $candidate[$field];
            }
        }

        return $hosts;
    }

    /**
     * @param mixed $value Candidate text to measure without trimming or Unicode character counting.
     * @param string $limit Configured byte limit name.
     * @param bool $nullable Whether null is meaningful and accepted.
     * @param bool $nonempty Whether an empty string is prohibited while whitespace remains valid.
     * @return string|null Exact candidate bytes.
     * @throws InvalidArgumentException When type or byte bounds are invalid.
     */
    private function boundedText(mixed $value, string $limit, bool $nullable = false, bool $nonempty = false): ?string
    {
        if ($value === null && $nullable) {
            return null;
        }

        if (!is_string($value) || ($nonempty && $value === '') || strlen($value) > $this->limits->get($limit)) {
            throw new InvalidArgumentException('The field must contain text within the configured byte limit.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $values Validated candidate.
     * @param string $field String attribute name.
     * @return string Verified scalar value, without PHPDoc casts.
     * @throws LogicException When the validation pipeline failed to establish its invariant.
     */
    private function string(array $values, string $field): string
    {
        $value = $values[$field] ?? null;

        if (!is_string($value)) {
            throw new LogicException('Validated monitor input is missing a string field.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $values Validated candidate.
     * @param string $field Array attribute name.
     * @return array<array-key, mixed> Verified collection value.
     * @throws LogicException When the validation pipeline failed to establish its invariant.
     */
    private function array(array $values, string $field): array
    {
        $value = $values[$field] ?? null;

        if (!is_array($value)) {
            throw new LogicException('Validated monitor input is missing a collection field.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $values Normalized candidate.
     * @param string $field Integer attribute name.
     * @return int Verified integer value.
     * @throws LogicException When the normalized value is not an integer.
     */
    private function integer(array $values, string $field): int
    {
        $value = $values[$field] ?? null;

        if (!is_int($value)) {
            throw new LogicException('Validated monitor input is missing an integer field.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $values Fully normalized input.
     * @param MonitorType $type Validated variant identity.
     * @return MonitorInput Complete typed definition; all secrets remain private data.
     */
    private function toInput(array $values, MonitorType $type): MonitorInput
    {
        $http = null;

        if ($type === MonitorType::Http || $type === MonitorType::Keyword) {
            $http = new HttpMonitorConfiguration(
                $this->string($values, 'url'),
                HttpMethod::from($this->string($values, 'method')),
                $this->array($values, 'headers'),
                isset($values['body']) ? $this->string($values, 'body') : null,
                $this->integer($values, 'expected_status'),
                $values['follow_redirects'] === true,
            );
        }

        $configuration = match ($type) {
            MonitorType::Http => $http,
            MonitorType::Keyword => new KeywordMonitorConfiguration(
                $http,
                $this->string($values, 'expected_keyword'),
                KeywordMode::from($this->string($values, 'keyword_mode')),
            ),
            MonitorType::Tls => new TlsMonitorConfiguration(
                $this->string($values, 'tls_host'),
                $this->integer($values, 'tls_port'),
                isset($values['tls_expiry_warning_days']) ? $this->integer($values, 'tls_expiry_warning_days') : null,
            ),
            MonitorType::Dns => new DnsMonitorConfiguration(
                $this->string($values, 'dns_name'),
                DnsRecordType::from($this->string($values, 'dns_record_type')),
                $this->array($values, 'dns_expected_values'),
                isset($values['dns_resolver']) ? $this->string($values, 'dns_resolver') : null,
            ),
            MonitorType::Heartbeat => new HeartbeatMonitorConfiguration($this->integer($values, 'heartbeat_grace_seconds')),
        };

        return new MonitorInput(
            $this->string($values, 'name'),
            $this->integer($values, 'interval_seconds'),
            $this->integer($values, 'timeout_ms'),
            $this->integer($values, 'confirmation_threshold'),
            MonitorRegions::fromValues($this->array($values, 'regions')),
            $configuration,
        );
    }
}
